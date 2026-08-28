<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use App\Services\ContentReview\Support\StrictJsonSchemaAdapter;
use App\Services\ContentReview\Support\UsdAmountConverter;
use Illuminate\Http\Client\Response;

final class OpenRouterContentReviewProvider extends HttpContentReviewProvider
{
    public function __construct(
        private readonly ProviderCostCalculator $costs,
        private readonly StrictJsonSchemaAdapter $strictSchema,
        private readonly UsdAmountConverter $amounts,
    ) {}

    public function name(): string
    {
        return 'openrouter';
    }

    protected function configKey(): string
    {
        return 'openrouter';
    }

    protected function headers(string $apiKey): array
    {
        return [
            'authorization' => 'Bearer '.$apiKey,
            'content-type' => 'application/json',
        ];
    }

    protected function endpoint(ProviderReviewRequest $request): string
    {
        return $this->baseUrl('https://openrouter.ai/api/v1').'/chat/completions';
    }

    protected function body(ProviderReviewRequest $request): array
    {
        $strategy = $request->structuredOutputStrategy();

        return array_replace([
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($request, $strategy)],
                ['role' => 'user', 'content' => $this->contentParts($request)],
            ],
            'usage' => ['include' => true],
        ], $this->structuredOutputParameters($request, $strategy));
    }

    protected function truncationFinishReason(): string
    {
        return 'length';
    }

    protected function textPart(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    protected function imagePart(string $mime, string $bytes): array
    {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)],
        ];
    }

    protected function metrics(Response $response, ProviderReviewRequest $request, int $latencyMs): ProviderCallMetrics
    {
        $model = $this->stringOrNull($response->json('model')) ?? $request->model;
        $inputTokens = $this->intOrNull($response->json('usage.prompt_tokens'));
        $outputTokens = $this->intOrNull($response->json('usage.completion_tokens'));

        return new ProviderCallMetrics(
            $model,
            $inputTokens,
            $outputTokens,
            $this->costMicros($response, $model, $inputTokens, $outputTokens),
            $latencyMs,
            $this->requestId($response),
            $this->stringOrNull($response->json('choices.0.finish_reason')),
        );
    }

    protected function extractPayload(ProviderReviewRequest $request, Response $response, ProviderCallMetrics $metrics): array
    {
        if (is_array($response->json())) {
            $raw = $request->structuredOutputStrategy() === StructuredOutputStrategy::Tool
                ? $this->toolArguments($response)
                : $this->stringOrNull($response->json('choices.0.message.content'));

            $decoded = $raw === null ? null : json_decode($this->stripCodeFence($raw), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->failUnusableOutput($metrics);
    }

    protected function requestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('x-request-id'))
            ?? $this->stringOrNull($response->json('id'));
    }

    private function costMicros(Response $response, string $model, ?int $inputTokens, ?int $outputTokens): ?int
    {
        $reported = $response->json('usage.cost');

        if (is_string($reported) || is_numeric($reported)) {
            $micros = $this->amounts->toMicros($this->decimalString($reported));

            if ($micros !== null) {
                return $micros;
            }
        }

        return $this->costs->costMicros($this->name(), $model, $inputTokens, $outputTokens);
    }

    private function decimalString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return sprintf('%.12F', $value);
    }

    private function structuredOutputParameters(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): array
    {
        return match ($strategy) {
            StructuredOutputStrategy::Tool => [
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => self::TOOL_NAME,
                        'description' => self::TOOL_DESCRIPTION,
                        'parameters' => $request->resultSchema,
                    ],
                ]],
                'tool_choice' => ['type' => 'function', 'function' => ['name' => self::TOOL_NAME]],
            ],
            StructuredOutputStrategy::JsonSchema => [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => self::TOOL_NAME,
                        'strict' => true,
                        'schema' => $this->strictSchema->apply($request->resultSchema),
                    ],
                ],
            ],
            StructuredOutputStrategy::JsonObject => [
                'response_format' => ['type' => 'json_object'],
            ],
        };
    }

    private function toolArguments(Response $response): ?string
    {
        $calls = $response->json('choices.0.message.tool_calls');

        if (! is_array($calls)) {
            return null;
        }

        foreach ($calls as $call) {
            $function = is_array($call) ? ($call['function'] ?? null) : null;

            if (is_array($function) && ($function['name'] ?? null) === self::TOOL_NAME) {
                return $this->stringOrNull($function['arguments'] ?? null);
            }
        }

        return null;
    }
}

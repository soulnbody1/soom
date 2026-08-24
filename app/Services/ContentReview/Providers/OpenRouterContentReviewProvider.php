<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use App\Services\ContentReview\Support\StrictJsonSchemaAdapter;
use App\Services\ContentReview\Support\UsdAmountConverter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenRouterContentReviewProvider implements ContentReviewProvider
{
    private const TOOL_NAME = 'record_content_review';

    public function __construct(
        private readonly ProviderCostCalculator $costs,
        private readonly StrictJsonSchemaAdapter $strictSchema,
        private readonly UsdAmountConverter $amounts,
    ) {}

    public function name(): string
    {
        return 'openrouter';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function analyze(ProviderReviewRequest $request): ProviderReviewResponse
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderAuthFailed);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'authorization' => 'Bearer '.$apiKey,
                'content-type' => 'application/json',
            ])
                ->timeout($request->timeoutSeconds)
                ->post($this->endpoint(), $this->body($request));
        } catch (ConnectionException) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderTimeout);
        } catch (Throwable) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
        }

        $this->assertSuccessful($response);

        $metrics = $this->metrics($response, $request, (int) round((microtime(true) - $startedAt) * 1000));

        return new ProviderReviewResponse(
            $this->extractPayload($request, $response, $metrics),
            $metrics->model,
            $metrics->inputTokens,
            $metrics->outputTokens,
            $metrics->costMicros,
            $metrics->latencyMs,
            $metrics->providerRequestId,
        );
    }

    private function metrics(Response $response, ProviderReviewRequest $request, int $latencyMs): ProviderCallMetrics
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

    private function endpoint(): string
    {
        return rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/')
            .'/chat/completions';
    }

    private function body(ProviderReviewRequest $request): array
    {
        $strategy = $request->structuredOutputStrategy();

        return array_replace([
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($request, $strategy)],
                ['role' => 'user', 'content' => $this->buildContent($request)],
            ],
            'usage' => ['include' => true],
        ], $this->structuredOutputParameters($request, $strategy));
    }

    private function systemPrompt(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): string
    {
        if ($strategy !== StructuredOutputStrategy::JsonObject) {
            return $request->policyInstructions;
        }

        return $request->policyInstructions
            ."\n\nReturn a single JSON object that validates against this schema, and nothing else:\n"
            .(string) json_encode($request->resultSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function structuredOutputParameters(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): array
    {
        return match ($strategy) {
            StructuredOutputStrategy::Tool => [
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => self::TOOL_NAME,
                        'description' => 'Record the structured content review result.',
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

    private function buildContent(ProviderReviewRequest $request): array
    {
        $content = [];

        foreach ($request->images as $image) {
            if (($image['bytes'] ?? null) === null) {
                continue;
            }

            $ref = (string) ($image['ref'] ?? '');

            if ($ref !== '') {
                $content[] = ['type' => 'text', 'text' => 'IMAGE '.$ref];
            }

            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.(string) $image['mime'].';base64,'.base64_encode((string) $image['bytes']),
                ],
            ];
        }

        $content[] = ['type' => 'text', 'text' => $this->buildTextBlocks($request)];

        return $content;
    }

    private function buildTextBlocks(ProviderReviewRequest $request): string
    {
        $parts = [];

        foreach ($request->textBlocks as $block) {
            $field = (string) ($block['field'] ?? 'other');
            $locale = (string) ($block['locale'] ?? 'ar');
            $value = (string) ($block['value'] ?? '');

            $parts[] = "<<<FIELD:{$field}:{$locale}>>>\n{$value}\n<<<END>>>";
        }

        $facts = [];

        foreach ($request->structuredFacts as $key => $value) {
            $facts[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return implode("\n\n", array_merge($parts, ['<<<FIELD:facts>>>', implode("\n", $facts), '<<<END>>>']));
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            if (is_array($response->json('error'))) {
                throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
            }

            return;
        }

        throw ContentReviewProviderException::of(match (true) {
            in_array($response->status(), [401, 402, 403], true) => ContentReviewErrorCode::ProviderAuthFailed,
            in_array($response->status(), [408, 504], true) => ContentReviewErrorCode::ProviderTimeout,
            $response->status() === 429 => ContentReviewErrorCode::ProviderRateLimited,
            default => ContentReviewErrorCode::ProviderUnavailable,
        });
    }

    private function extractPayload(ProviderReviewRequest $request, Response $response, ProviderCallMetrics $metrics): array
    {
        if (! is_array($response->json())) {
            throw ContentReviewProviderException::afterCall(ContentReviewErrorCode::InvalidStructuredOutput, $metrics);
        }

        $raw = $request->structuredOutputStrategy() === StructuredOutputStrategy::Tool
            ? $this->toolArguments($response)
            : $this->stringOrNull($response->json('choices.0.message.content'));

        $decoded = $raw === null ? null : json_decode($this->stripCodeFence($raw), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw ContentReviewProviderException::afterCall(
            $metrics->finishReason === 'length'
                ? ContentReviewErrorCode::OutputTruncated
                : ContentReviewErrorCode::InvalidStructuredOutput,
            $metrics
        );
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

    private function stripCodeFence(string $raw): string
    {
        $trimmed = trim($raw);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = (string) preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $trimmed);

        return trim((string) preg_replace('/```$/', '', trim($trimmed)));
    }

    private function requestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('x-request-id'))
            ?? $this->stringOrNull($response->json('id'));
    }

    private function apiKey(): string
    {
        return trim((string) config('services.openrouter.api_key'));
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

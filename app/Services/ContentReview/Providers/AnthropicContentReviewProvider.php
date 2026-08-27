<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use Illuminate\Http\Client\Response;

final class AnthropicContentReviewProvider extends HttpContentReviewProvider
{
    public function __construct(private readonly ProviderCostCalculator $costs) {}

    public function name(): string
    {
        return 'anthropic';
    }

    protected function configKey(): string
    {
        return 'anthropic';
    }

    protected function headers(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
            'content-type' => 'application/json',
        ];
    }

    protected function endpoint(ProviderReviewRequest $request): string
    {
        return $this->baseUrl('https://api.anthropic.com').'/v1/messages';
    }

    protected function body(ProviderReviewRequest $request): array
    {
        return [
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens,
            'system' => $request->policyInstructions,
            'tools' => [[
                'name' => self::TOOL_NAME,
                'description' => self::TOOL_DESCRIPTION,
                'input_schema' => $request->resultSchema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
            'messages' => [[
                'role' => 'user',
                'content' => $this->contentParts($request),
            ]],
        ];
    }

    protected function truncationFinishReason(): string
    {
        return 'max_tokens';
    }

    protected function textPart(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    protected function imagePart(string $mime, string $bytes): array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mime,
                'data' => base64_encode($bytes),
            ],
        ];
    }

    protected function metrics(Response $response, ProviderReviewRequest $request, int $latencyMs): ProviderCallMetrics
    {
        $model = $this->stringOrNull($response->json('model')) ?? $request->model;
        $inputTokens = $this->intOrNull($response->json('usage.input_tokens'));
        $outputTokens = $this->intOrNull($response->json('usage.output_tokens'));

        return new ProviderCallMetrics(
            $model,
            $inputTokens,
            $outputTokens,
            $this->costs->costMicros($this->name(), $model, $inputTokens, $outputTokens),
            $latencyMs,
            $this->requestId($response),
            $this->stringOrNull($response->json('stop_reason')),
        );
    }

    protected function extractPayload(ProviderReviewRequest $request, Response $response, ProviderCallMetrics $metrics): array
    {
        $blocks = $response->json('content');

        if (is_array($response->json()) && is_array($blocks)) {
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL_NAME) {
                    $input = $block['input'] ?? null;

                    if (is_array($input)) {
                        return $input;
                    }
                }
            }
        }

        $this->failUnusableOutput($metrics);
    }

    protected function requestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('request-id'))
            ?? $this->stringOrNull($response->json('id'));
    }
}

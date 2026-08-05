<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AnthropicContentReviewProvider implements ContentReviewProvider
{
    private const TOOL_NAME = 'record_content_review';

    public function __construct(private readonly ProviderCostCalculator $costs) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function analyze(ProviderReviewRequest $request): ProviderReviewResponse
    {
        $apiKey = (string) config('services.anthropic.api_key');

        if ($apiKey === '') {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderAuthFailed);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
                ->timeout($request->timeoutSeconds)
                ->post(rtrim((string) config('services.anthropic.base_url'), '/').'/v1/messages', [
                    'model' => $request->model,
                    'max_tokens' => $request->maxOutputTokens,
                    'system' => $request->policyInstructions,
                    'tools' => [[
                        'name' => self::TOOL_NAME,
                        'description' => 'Record the structured content review result.',
                        'input_schema' => $request->resultSchema,
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->buildContent($request),
                    ]],
                ]);
        } catch (ConnectionException) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderTimeout);
        } catch (Throwable) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
        }

        $this->assertSuccessful($response);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = $this->extractPayload($response);
        $model = $this->stringOrNull($response->json('model')) ?? $request->model;
        $inputTokens = $this->intOrNull($response->json('usage.input_tokens'));
        $outputTokens = $this->intOrNull($response->json('usage.output_tokens'));

        return new ProviderReviewResponse(
            $payload,
            $model,
            $inputTokens,
            $outputTokens,
            $this->costs->costMicros($model, $inputTokens, $outputTokens),
            $latencyMs,
            $this->requestId($response),
        );
    }

    private function requestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('request-id'))
            ?? $this->stringOrNull($response->json('id'));
    }

    private function buildContent(ProviderReviewRequest $request): array
    {
        $content = [];

        foreach ($request->images as $image) {
            if (($image['bytes'] ?? null) === null) {
                continue;
            }

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => (string) $image['mime'],
                    'data' => base64_encode((string) $image['bytes']),
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
            return;
        }

        throw ContentReviewProviderException::of(match (true) {
            $response->status() === 401 || $response->status() === 403 => ContentReviewErrorCode::ProviderAuthFailed,
            $response->status() === 408 => ContentReviewErrorCode::ProviderTimeout,
            $response->status() === 429 => ContentReviewErrorCode::ProviderRateLimited,
            default => ContentReviewErrorCode::ProviderUnavailable,
        });
    }

    private function extractPayload(Response $response): array
    {
        if (! is_array($response->json())) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::InvalidStructuredOutput);
        }

        $blocks = $response->json('content');

        if (! is_array($blocks)) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::InvalidStructuredOutput);
        }

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

        throw ContentReviewProviderException::of(ContentReviewErrorCode::InvalidStructuredOutput);
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

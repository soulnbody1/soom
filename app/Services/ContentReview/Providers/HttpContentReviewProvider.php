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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class HttpContentReviewProvider implements ContentReviewProvider
{
    protected const TOOL_NAME = 'record_content_review';

    protected const TOOL_DESCRIPTION = 'Record the structured content review result.';

    abstract protected function configKey(): string;

    abstract protected function headers(string $apiKey): array;

    abstract protected function endpoint(ProviderReviewRequest $request): string;

    abstract protected function body(ProviderReviewRequest $request): array;

    abstract protected function metrics(Response $response, ProviderReviewRequest $request, int $latencyMs): ProviderCallMetrics;

    abstract protected function extractPayload(ProviderReviewRequest $request, Response $response, ProviderCallMetrics $metrics): array;

    abstract protected function truncationFinishReason(): string;

    abstract protected function textPart(string $text): array;

    abstract protected function imagePart(string $mime, string $bytes): array;

    abstract protected function requestId(Response $response): ?string;

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
            $response = Http::withHeaders($this->headers($apiKey))
                ->timeout($request->timeoutSeconds)
                ->post($this->endpoint($request), $this->body($request));
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

    protected function apiKey(): string
    {
        return trim((string) config('services.'.$this->configKey().'.api_key'));
    }

    protected function baseUrl(string $default): string
    {
        return rtrim((string) config('services.'.$this->configKey().'.base_url', $default), '/');
    }

    protected function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            if (is_array($response->json('error'))) {
                throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
            }

            return;
        }

        throw ContentReviewProviderException::of($this->classifyByStatusCode($response->status()));
    }

    protected function classifyByStatusCode(int $status): ContentReviewErrorCode
    {
        return match (true) {
            in_array($status, [401, 402, 403], true) => ContentReviewErrorCode::ProviderAuthFailed,
            in_array($status, [408, 504], true) => ContentReviewErrorCode::ProviderTimeout,
            $status === 429 => ContentReviewErrorCode::ProviderRateLimited,
            default => ContentReviewErrorCode::ProviderUnavailable,
        };
    }

    protected function failUnusableOutput(ProviderCallMetrics $metrics): never
    {
        throw ContentReviewProviderException::afterCall(
            $metrics->finishReason === $this->truncationFinishReason()
                ? ContentReviewErrorCode::OutputTruncated
                : ContentReviewErrorCode::InvalidStructuredOutput,
            $metrics
        );
    }

    protected function systemPrompt(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): string
    {
        if ($strategy !== StructuredOutputStrategy::JsonObject) {
            return $request->policyInstructions;
        }

        return $request->policyInstructions
            ."\n\nReturn a single JSON object that validates against this schema, and nothing else:\n"
            .(string) json_encode($request->resultSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function contentParts(ProviderReviewRequest $request): array
    {
        $parts = [];

        foreach ($request->images as $image) {
            if (($image['bytes'] ?? null) === null) {
                continue;
            }

            $ref = (string) ($image['ref'] ?? '');

            if ($ref !== '') {
                $parts[] = $this->textPart('IMAGE '.$ref);
            }

            $parts[] = $this->imagePart((string) $image['mime'], (string) $image['bytes']);
        }

        $parts[] = $this->textPart($this->contentText($request));

        return $parts;
    }

    protected function contentText(ProviderReviewRequest $request): string
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

    protected function stripCodeFence(string $raw): string
    {
        $trimmed = trim($raw);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = (string) preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $trimmed);

        return trim((string) preg_replace('/```$/', '', trim($trimmed)));
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\Services\ContentReview\Contracts\ContentReviewProvider;

final class FakeContentReviewProvider implements ContentReviewProvider
{
    private array $queue = [];

    private ?ContentReviewErrorCode $failure = null;

    private ?ProviderCallMetrics $failureMetrics = null;

    private int $calls = 0;

    private array $requests = [];

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function respondWith(array $payload, ?int $costMicros = 1200, ?int $inputTokens = 500, ?int $outputTokens = 120): self
    {
        $this->queue[] = [
            'payload' => $payload,
            'cost_micros' => $costMicros,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
        $this->failure = null;

        return $this;
    }

    public function failWith(ContentReviewErrorCode $code, ?ProviderCallMetrics $metrics = null): self
    {
        $this->failure = $code;
        $this->failureMetrics = $metrics;

        return $this;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?ProviderReviewRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    public function reset(): self
    {
        $this->queue = [];
        $this->failure = null;
        $this->failureMetrics = null;
        $this->calls = 0;
        $this->requests = [];

        return $this;
    }

    public function analyze(ProviderReviewRequest $request): ProviderReviewResponse
    {
        $this->calls++;
        $this->requests[] = $request;

        if ($this->failure !== null) {
            throw $this->failureMetrics === null
                ? ContentReviewProviderException::of($this->failure)
                : ContentReviewProviderException::afterCall($this->failure, $this->failureMetrics);
        }

        $scripted = array_shift($this->queue) ?? [
            'payload' => $this->defaultPayload(),
            'cost_micros' => 1200,
            'input_tokens' => 500,
            'output_tokens' => 120,
        ];

        return new ProviderReviewResponse(
            $scripted['payload'],
            $request->model,
            $scripted['input_tokens'],
            $scripted['output_tokens'],
            $scripted['cost_micros'],
            5,
            'fake-'.$this->calls,
        );
    }

    private function defaultPayload(): array
    {
        return [
            'recommendation' => 'needs_human',
            'confidence' => 50,
            'risk_level' => 'medium',
            'requires_human_review' => true,
            'summary_ar' => 'لم يتم تحديد نتيجة حاسمة.',
            'summary_en' => 'No conclusive result.',
            'categories' => [],
            'violations' => [],
            'findings' => [],
            'policy_checks' => [],
            'missing_information' => [],
        ];
    }
}

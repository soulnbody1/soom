<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Support\ProviderCallGuard;
use App\Services\ContentReview\Support\ProviderSelectionResolver;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewPolicyResolver;
use App\Services\ContentReview\Support\ReviewPromptRenderer;
use Throwable;

final class TestContentReviewProviderAction
{
    private const PROBE_TEXT = 'Connectivity probe. Reply with a neutral structured result.';

    private const PROBE_MAX_OUTPUT_TOKENS = 256;

    public function __construct(
        private readonly ContentReviewProviderFactory $providers,
        private readonly ReviewModeResolver $modes,
        private readonly ReviewPolicyResolver $policies,
        private readonly ReviewPromptRenderer $renderer,
        private readonly ProviderCallGuard $callGuard,
        private readonly ProviderSelectionResolver $selection,
    ) {}

    public function execute(ReviewableSubjectType $type): array
    {
        $this->callGuard->assertOutsideTransaction();

        $settings = $this->modes->effectiveSettings($type);
        $providerName = $this->selection->provider($settings);
        $model = $this->selection->model($settings);
        $policy = $this->policies->activeRecord($type)?->toValueObject() ?? ReviewPolicy::fromArray([]);

        $request = new ProviderReviewRequest(
            $type,
            self::PROBE_TEXT,
            $this->renderer->resultSchema($policy),
            [['field' => 'title', 'locale' => 'ar', 'value' => self::PROBE_TEXT]],
            [],
            [],
            $model,
            self::PROBE_MAX_OUTPUT_TOKENS,
            $settings->timeoutSeconds(),
            $policy->locales(),
            [],
            $this->selection->descriptor($settings),
        );

        $startedAt = microtime(true);

        try {
            $response = $this->providers->make($providerName)->analyze($request);
        } catch (ContentReviewProviderException $exception) {
            return $this->failure($providerName, $model, $exception->errorCode(), $startedAt);
        } catch (Throwable) {
            return $this->failure($providerName, $model, ContentReviewErrorCode::ProviderUnavailable, $startedAt);
        }

        return [
            'ok' => true,
            'provider' => $providerName,
            'model' => $response->model,
            'latency_ms' => $response->latencyMs ?? $this->elapsedMs($startedAt),
            'error_code' => null,
            'error_label' => null,
        ];
    }

    private function failure(string $provider, string $model, ContentReviewErrorCode $code, mixed $startedAt): array
    {
        return [
            'ok' => false,
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => $this->elapsedMs($startedAt),
            'error_code' => $code->value,
            'error_label' => __('content_review.errors.'.$code->value),
        ];
    }

    private function elapsedMs(mixed $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}

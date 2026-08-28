<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Models\ContentReview\ContentReview;

final class ContentReviewLogContext
{
    private const EXTRA_KEYS = [
        'result',
        'stage',
        'attempt_limit',
        'images_selected',
        'images_analyzed',
        'image_cache_hits',
        'queue_delay_ms',
        'retryable',
        'alert_code',
        'alert_state',
        'observed',
        'threshold',
        'scanned',
        'created',
        'skipped',
        'repaired',
        'dry_run',
    ];

    private const REVIEW_KEYS = [
        'review_public_id',
        'subject_type',
        'subject_id',
        'mode',
        'status',
        'outcome',
        'reason_code',
        'error_code',
        'provider',
        'model',
        'duration_ms',
        'attempt',
        'input_tokens',
        'output_tokens',
        'cost_micros',
    ];

    public function forReview(ContentReview $review, array $extra = []): array
    {
        $context = [
            'review_public_id' => (string) $review->public_id,
            'subject_type' => $review->subject_type->value,
            'subject_id' => (int) $review->subject_id,
            'mode' => $review->mode->value,
            'status' => $review->status->value,
            'outcome' => $review->outcome?->value,
            'reason_code' => $review->reason_code,
            'error_code' => $review->error_code?->value,
            'provider' => $review->provider,
            'model' => $review->model,
            'duration_ms' => $review->duration_ms === null ? null : (int) $review->duration_ms,
            'attempt' => (int) $review->attempt,
            'input_tokens' => $review->input_tokens === null ? null : (int) $review->input_tokens,
            'output_tokens' => $review->output_tokens === null ? null : (int) $review->output_tokens,
            'cost_micros' => $review->cost_micros === null ? null : (int) $review->cost_micros,
        ];

        return array_replace($context, $this->operational($extra));
    }

    public function operational(array $extra): array
    {
        $context = [];

        foreach (self::EXTRA_KEYS as $key) {
            if (! array_key_exists($key, $extra)) {
                continue;
            }

            $value = $extra[$key];

            if ($value === null || is_scalar($value)) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    public static function allowedKeys(): array
    {
        return array_merge(self::REVIEW_KEYS, self::EXTRA_KEYS);
    }
}

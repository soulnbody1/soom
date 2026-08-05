<?php

declare(strict_types=1);

namespace App\Http\Resources\ContentReview;

use App\Models\ContentReview\ContentReviewSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentReviewSetting
 */
final class ContentReviewSettingsResource extends JsonResource
{
    private const EXPOSED_KEYS = [
        'enabled',
        'mode',
        'provider',
        'model',
        'timeout_seconds',
        'max_attempts',
        'backoff_seconds',
        'max_concurrent',
        'max_output_tokens',
        'daily_budget_micros',
        'monthly_budget_micros',
        'analyze_images',
        'circuit_breaker',
        'automation',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'scope' => $this->scope,
            'version_number' => (int) $this->version_number,
            'is_active' => (bool) $this->is_active,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_by' => $this->relationLoaded('creator') && $this->creator !== null ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'settings' => $this->exposedSettings(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function exposedSettings(): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        return array_intersect_key($settings, array_flip(self::EXPOSED_KEYS));
    }
}

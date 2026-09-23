<?php

declare(strict_types=1);

namespace App\Models\ContentReview;

use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\Concerns\BelongsToMarket;
use App\Models\ContentReview\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentReviewSetting extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $table = 'content_review_settings';

    protected $fillable = [
        'public_id',
        'scope',
        'version_number',
        'settings',
        'is_active',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (ContentReviewSetting $setting): void {
            if (array_keys($setting->getDirty()) !== ['is_active']) {
                throw ContentReviewException::domain('settings_in_use');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? false);
    }

    public function mode(): ?ReviewMode
    {
        return ReviewMode::tryFrom((string) ($this->settings['mode'] ?? ''));
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function automation(): array
    {
        $automation = $this->settings['automation'] ?? [];

        return is_array($automation) ? $automation : [];
    }

    public function circuitBreaker(): array
    {
        $breaker = $this->settings['circuit_breaker'] ?? [];

        return is_array($breaker) ? $breaker : [];
    }
}

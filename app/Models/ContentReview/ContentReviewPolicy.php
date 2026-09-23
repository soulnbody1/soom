<?php

declare(strict_types=1);

namespace App\Models\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\Models\Concerns\BelongsToMarket;
use App\Models\ContentReview\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContentReviewPolicy extends Model
{
    use BelongsToMarket, HasPublicId;

    protected $table = 'content_review_policies';

    protected $fillable = [
        'public_id',
        'subject_type',
        'version_number',
        'name',
        'policy',
        'prompt_version',
        'result_schema_version',
        'is_active',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'subject_type' => ReviewableSubjectType::class,
        'policy' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (ContentReviewPolicy $policy): void {
            if ($policy->isUsed() && ! $policy->onlyActivationChanged()) {
                throw ContentReviewException::domain('policy_in_use');
            }
        });

        self::deleting(function (ContentReviewPolicy $policy): void {
            if ($policy->isUsed()) {
                throw ContentReviewException::domain('policy_in_use');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ContentReview::class, 'policy_id');
    }

    public function isUsed(): bool
    {
        return ContentReview::where('policy_id', $this->id)->exists();
    }

    public function toValueObject(): ReviewPolicy
    {
        return new ReviewPolicy(
            (array) $this->policy,
            $this->id,
            (int) $this->version_number,
            (string) $this->prompt_version,
            (int) $this->result_schema_version,
        );
    }

    private function onlyActivationChanged(): bool
    {
        return array_keys($this->getDirty()) === ['is_active'];
    }
}

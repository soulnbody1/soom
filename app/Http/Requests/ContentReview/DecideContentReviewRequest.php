<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Services\ContentReview\Support\ContentReviewOverrideGuard;
use Illuminate\Foundation\Http\FormRequest;

final class DecideContentReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:'.ContentReviewOverrideGuard::MAX_REASON_CHARS],
        ];
    }

    public function decision(): ContentReviewDecisionType
    {
        return $this->string('decision')->value() === 'approve'
            ? ContentReviewDecisionType::Approved
            : ContentReviewDecisionType::Rejected;
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason', ''));
    }
}

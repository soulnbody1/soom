<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\DTO\ContentReview\MetricsRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ContentReviewMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'range' => ['sometimes', Rule::in(MetricsRange::presets())],
            'subject_type' => ['sometimes', Rule::in(array_column(ReviewableSubjectType::cases(), 'value'))],
            'date_from' => ['sometimes', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }

            $from = Carbon::createFromFormat('Y-m-d', (string) $this->input('date_from'))->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', (string) $this->input('date_to'))->endOfDay();
            $maxDays = max(1, (int) config('content_review.metrics.max_range_days', 92));

            if ($from->diffInDays($to) + 1 > $maxDays) {
                $validator->errors()->add('date_to', __('validation.max.numeric', [
                    'attribute' => 'date_to',
                    'max' => (string) $maxDays,
                ]));
            }
        });
    }

    public function range(): MetricsRange
    {
        if ($this->filled('date_from') && $this->filled('date_to')) {
            return MetricsRange::custom(
                Carbon::createFromFormat('Y-m-d', (string) $this->input('date_from')),
                Carbon::createFromFormat('Y-m-d', (string) $this->input('date_to')),
            );
        }

        return MetricsRange::preset(
            (string) $this->input('range', config('content_review.metrics.default_range', MetricsRange::LAST_7_DAYS))
        );
    }
}

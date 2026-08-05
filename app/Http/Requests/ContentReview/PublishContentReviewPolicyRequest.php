<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublishContentReviewPolicyRequest extends FormRequest
{
    private const FIELDS = ['title', 'description', 'images', 'price', 'schedule', 'location', 'other'];

    private const LOCALES = ['ar', 'en'];

    private const ALLOWED_KEYS = [
        'locales',
        'analyzed_text_fields',
        'analyze_images',
        'max_images',
        'image_max_edge_px',
        'prohibited_categories',
        'auto_reject_categories',
        'human_review_categories',
        'violation_codes',
        'thresholds',
        'max_risk_level_for_auto_approve',
        'deterministic_rules',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(array_column(ReviewableSubjectType::cases(), 'value'))],
            'name' => ['required', 'string', 'max:120'],
            'prompt_version' => ['nullable', 'string', 'max:40'],
            'result_schema_version' => ['nullable', 'integer', 'min:1', 'max:255'],

            'policy' => ['required', 'array'],
            'policy.locales' => ['required', 'array', 'min:1', 'max:'.count(self::LOCALES)],
            'policy.locales.*' => ['string', Rule::in(self::LOCALES)],
            'policy.analyzed_text_fields' => ['required', 'array', 'min:1'],
            'policy.analyzed_text_fields.*' => ['string', Rule::in(self::FIELDS)],
            'policy.analyze_images' => ['required', 'boolean'],
            'policy.max_images' => ['required', 'integer', 'min:0', 'max:20'],
            'policy.image_max_edge_px' => ['nullable', 'integer', 'min:64', 'max:4096'],

            'policy.prohibited_categories' => ['present', 'array', 'max:100'],
            'policy.prohibited_categories.*' => ['string', 'max:80'],
            'policy.auto_reject_categories' => ['present', 'array', 'max:100'],
            'policy.auto_reject_categories.*' => ['string', 'max:80'],
            'policy.human_review_categories' => ['present', 'array', 'max:100'],
            'policy.human_review_categories.*' => ['string', 'max:80'],
            'policy.violation_codes' => ['required', 'array', 'min:1', 'max:100'],
            'policy.violation_codes.*' => ['string', 'max:80'],

            'policy.thresholds' => ['required', 'array'],
            'policy.thresholds.min_confidence_approve' => ['required', 'integer', 'between:0,100'],
            'policy.thresholds.min_confidence_reject' => ['required', 'integer', 'between:0,100'],
            'policy.thresholds.grey_zone_low' => ['required', 'integer', 'between:0,100'],
            'policy.thresholds.grey_zone_high' => ['required', 'integer', 'between:0,100'],

            'policy.max_risk_level_for_auto_approve' => [
                'required', 'string', Rule::in(array_column(ReviewRiskLevel::cases(), 'value')),
            ],

            'policy.deterministic_rules' => ['required', 'array'],
            'policy.deterministic_rules.min_description_length' => ['required', 'integer', 'min:0', 'max:10000'],
            'policy.deterministic_rules.require_at_least_one_image' => ['required', 'boolean'],
            'policy.deterministic_rules.forbid_contact_patterns' => ['required', 'boolean'],
            'policy.deterministic_rules.reserve_must_not_exceed_starting_multiplier' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $policy = (array) $this->input('policy', []);
            $thresholds = (array) ($policy['thresholds'] ?? []);
            $prohibited = $this->stringList($policy['prohibited_categories'] ?? []);

            $low = (int) ($thresholds['grey_zone_low'] ?? 0);
            $high = (int) ($thresholds['grey_zone_high'] ?? 0);

            if ($low > $high) {
                $validator->errors()->add('policy.thresholds.grey_zone_low', __('content_review.errors.policy_invalid'));
            }

            foreach (['auto_reject_categories', 'human_review_categories'] as $key) {
                foreach ($this->stringList($policy[$key] ?? []) as $category) {
                    if (! in_array($category, $prohibited, true)) {
                        $validator->errors()->add("policy.{$key}", __('content_review.errors.policy_invalid'));

                        break;
                    }
                }
            }

            if (count($this->stringList($policy['violation_codes'] ?? []))
                !== count(array_unique($this->stringList($policy['violation_codes'] ?? [])))) {
                $validator->errors()->add('policy.violation_codes', __('content_review.errors.policy_invalid'));
            }
        });
    }

    public function subjectType(): ReviewableSubjectType
    {
        return ReviewableSubjectType::from((string) $this->validated('subject_type'));
    }

    public function policyPayload(): array
    {
        $policy = array_intersect_key((array) $this->validated('policy'), array_flip(self::ALLOWED_KEYS));

        $policy['thresholds'] = array_intersect_key(
            (array) ($policy['thresholds'] ?? []),
            array_flip(['min_confidence_approve', 'min_confidence_reject', 'grey_zone_low', 'grey_zone_high'])
        );

        $policy['deterministic_rules'] = array_intersect_key(
            (array) ($policy['deterministic_rules'] ?? []),
            array_flip([
                'min_description_length',
                'require_at_least_one_image',
                'forbid_contact_patterns',
                'reserve_must_not_exceed_starting_multiplier',
            ])
        );

        return $policy;
    }

    public function promptVersion(): string
    {
        $version = (string) ($this->validated('prompt_version') ?? '');

        return $version === '' ? 'v1' : $version;
    }

    public function resultSchemaVersion(): int
    {
        return (int) ($this->validated('result_schema_version') ?? 1);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, array_filter($value, 'is_scalar')));
    }
}

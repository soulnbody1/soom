<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\Enums\ViolationSeverity;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\StructuredReviewResult;
use Illuminate\Support\Facades\Validator;

final class StructuredReviewResultValidator
{
    private const FIELDS = ['title', 'description', 'images', 'price', 'schedule', 'location', 'other'];

    private const MAX_CATEGORIES = 10;

    private const MAX_VIOLATIONS = 20;

    private const MAX_FINDINGS = 20;

    private const MAX_POLICY_CHECKS = 40;

    private const MAX_MISSING_INFORMATION = 10;

    private const MAX_IMAGE_CHECKS = 8;

    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    public function validate(array $payload, ReviewPolicy $policy): StructuredReviewResult
    {
        $payload = $this->pickKnownKeys($payload);

        $validator = Validator::make($payload, $this->rules($policy));

        if ($validator->fails()) {
            throw ContentReviewException::domain('invalid_structured_output');
        }

        if (! $this->isStrictInteger($payload['confidence'] ?? null)) {
            throw ContentReviewException::domain('invalid_structured_output');
        }

        if (! is_bool($payload['requires_human_review'] ?? null)) {
            throw ContentReviewException::domain('invalid_structured_output');
        }

        return new StructuredReviewResult(
            ReviewRecommendation::from((string) $payload['recommendation']),
            (int) $payload['confidence'],
            ReviewRiskLevel::from((string) $payload['risk_level']),
            (bool) $payload['requires_human_review'],
            $this->sanitizer->sanitize((string) $payload['summary_ar'], 600),
            isset($payload['summary_en']) ? $this->sanitizer->sanitize((string) $payload['summary_en'], 600) : null,
            $this->normalizeCategories($payload['categories'] ?? []),
            $this->normalizeViolations($payload['violations'] ?? []),
            $this->normalizeFindings($payload['findings'] ?? []),
            $this->normalizePolicyChecks($payload['policy_checks'] ?? []),
            $this->normalizeMissingInformation($payload['missing_information'] ?? []),
            $this->normalizeImageChecks($payload['image_checks'] ?? []),
        );
    }

    private function rules(ReviewPolicy $policy): array
    {
        $categories = $policy->prohibitedCategories();
        $violationCodes = $policy->violationCodes();

        return [
            'recommendation' => ['required', 'string', 'in:'.implode(',', array_column(ReviewRecommendation::cases(), 'value'))],
            'confidence' => ['required', 'integer', 'between:0,100'],
            'risk_level' => ['required', 'string', 'in:'.implode(',', array_column(ReviewRiskLevel::cases(), 'value'))],
            'requires_human_review' => ['required', 'boolean'],
            'summary_ar' => ['required', 'string', 'max:2000'],
            'summary_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'categories' => ['sometimes', 'array', 'max:'.self::MAX_CATEGORIES],
            'categories.*' => $categories === [] ? ['string'] : ['string', 'in:'.implode(',', $categories)],
            'violations' => ['sometimes', 'array', 'max:'.self::MAX_VIOLATIONS],
            'violations.*.code' => $violationCodes === [] ? ['required', 'string'] : ['required', 'string', 'in:'.implode(',', $violationCodes)],
            'violations.*.severity' => ['required', 'string', 'in:'.implode(',', array_column(ViolationSeverity::cases(), 'value'))],
            'violations.*.field' => ['required', 'string', 'in:'.implode(',', self::FIELDS)],
            'violations.*.evidence' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'findings' => ['sometimes', 'array', 'max:'.self::MAX_FINDINGS],
            'findings.*.field' => ['required', 'string', 'in:'.implode(',', self::FIELDS)],
            'findings.*.note' => ['required', 'string', 'max:2000'],
            'policy_checks' => ['sometimes', 'array', 'max:'.self::MAX_POLICY_CHECKS],
            'policy_checks.*.rule_code' => ['required', 'string', 'max:80'],
            'policy_checks.*.passed' => ['required', 'boolean'],
            'missing_information' => ['sometimes', 'array', 'max:'.self::MAX_MISSING_INFORMATION],
            'missing_information.*' => ['string', 'max:2000'],
            'image_checks' => ['sometimes', 'array', 'max:'.self::MAX_IMAGE_CHECKS],
            'image_checks.*.ref' => ['required', 'string', 'max:20'],
            'image_checks.*.verdict' => ['required', 'string', 'in:'.implode(',', array_column(ImageCheckVerdict::cases(), 'value'))],
            'image_checks.*.risk_level' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_column(ReviewRiskLevel::cases(), 'value'))],
            'image_checks.*.findings' => ['sometimes', 'array', 'max:5'],
            'image_checks.*.findings.*' => ['string', 'max:2000'],
        ];
    }

    private function pickKnownKeys(array $payload): array
    {
        $known = [
            'recommendation',
            'confidence',
            'risk_level',
            'requires_human_review',
            'summary_ar',
            'summary_en',
            'categories',
            'violations',
            'findings',
            'policy_checks',
            'missing_information',
            'image_checks',
        ];

        return array_intersect_key($payload, array_flip($known));
    }

    private function normalizeImageChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            return [];
        }

        $normalized = [];

        foreach (array_slice($checks, 0, self::MAX_IMAGE_CHECKS) as $check) {
            if (! is_array($check)) {
                continue;
            }

            $ref = $this->sanitizer->sanitize((string) ($check['ref'] ?? ''), 20);
            $verdict = ImageCheckVerdict::tryFrom((string) ($check['verdict'] ?? ''));

            if ($ref === '' || $verdict === null) {
                continue;
            }

            $findings = is_array($check['findings'] ?? null) ? $check['findings'] : [];

            $normalized[$ref] = [
                'ref' => $ref,
                'verdict' => $verdict->value,
                'risk_level' => ReviewRiskLevel::tryFrom((string) ($check['risk_level'] ?? ''))?->value,
                'findings' => array_values(array_map(
                    fn ($note): string => $this->sanitizer->sanitize((string) $note, 200),
                    array_slice($findings, 0, 5)
                )),
            ];
        }

        return $normalized;
    }

    private function isStrictInteger(mixed $value): bool
    {
        return is_int($value);
    }

    private function normalizeCategories(array $categories): array
    {
        return array_values(array_unique(array_map(
            fn ($category): string => $this->sanitizer->sanitize((string) $category, 80),
            array_slice($categories, 0, self::MAX_CATEGORIES)
        )));
    }

    private function normalizeViolations(array $violations): array
    {
        return array_values(array_map(fn (array $violation): array => [
            'code' => (string) $violation['code'],
            'severity' => (string) $violation['severity'],
            'field' => (string) $violation['field'],
            'evidence' => $this->sanitizer->sanitize((string) ($violation['evidence'] ?? ''), 200),
        ], array_slice($violations, 0, self::MAX_VIOLATIONS)));
    }

    private function normalizeFindings(array $findings): array
    {
        return array_values(array_map(fn (array $finding): array => [
            'field' => (string) $finding['field'],
            'note' => $this->sanitizer->sanitize((string) $finding['note'], 300),
        ], array_slice($findings, 0, self::MAX_FINDINGS)));
    }

    private function normalizePolicyChecks(array $checks): array
    {
        return array_values(array_map(fn (array $check): array => [
            'rule_code' => $this->sanitizer->sanitize((string) $check['rule_code'], 80),
            'passed' => (bool) $check['passed'],
        ], array_slice($checks, 0, self::MAX_POLICY_CHECKS)));
    }

    private function normalizeMissingInformation(array $items): array
    {
        return array_values(array_map(
            fn ($item): string => $this->sanitizer->sanitize((string) $item, 120),
            array_slice($items, 0, self::MAX_MISSING_INFORMATION)
        ));
    }
}

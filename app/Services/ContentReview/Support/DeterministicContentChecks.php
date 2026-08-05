<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\ReviewContentDTO;

final class DeterministicContentChecks
{
    private const CONTACT_PATTERNS = [
        '/\+?\d[\d\s\-()]{7,}\d/u',
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u',
        '/\b(whatsapp|واتساب|واتس اب|تلغرام|telegram)\b/iu',
        '/https?:\/\/\S+/iu',
    ];

    public function run(ReviewContentDTO $content, ReviewPolicy $policy): DeterministicCheckResult
    {
        $findings = [];

        $findings[] = $this->checkDescriptionLength($content, $policy);
        $findings[] = $this->checkImagePresence($content, $policy);
        $findings[] = $this->checkContactPatterns($content, $policy);
        $findings[] = $this->checkReserveRatio($content, $policy);

        $findings = array_values(array_filter($findings));
        $hardFailure = false;

        foreach ($findings as $finding) {
            if ($finding['passed'] === false && $finding['hard'] === true) {
                $hardFailure = true;
            }
        }

        return new DeterministicCheckResult($findings, $hardFailure);
    }

    private function checkDescriptionLength(ReviewContentDTO $content, ReviewPolicy $policy): ?array
    {
        $minimum = (int) $policy->deterministicRule('min_description_length', 0);

        if ($minimum <= 0) {
            return null;
        }

        $description = (string) $content->textFor('description');

        return [
            'rule_code' => 'min_description_length',
            'passed' => mb_strlen(trim($description)) >= $minimum,
            'hard' => true,
        ];
    }

    private function checkImagePresence(ReviewContentDTO $content, ReviewPolicy $policy): ?array
    {
        if ($policy->deterministicRule('require_at_least_one_image', false) !== true) {
            return null;
        }

        return [
            'rule_code' => 'require_at_least_one_image',
            'passed' => $content->imageCount() > 0,
            'hard' => true,
        ];
    }

    private function checkContactPatterns(ReviewContentDTO $content, ReviewPolicy $policy): ?array
    {
        if ($policy->deterministicRule('forbid_contact_patterns', false) !== true) {
            return null;
        }

        $haystack = '';

        foreach ($content->textBlocks as $block) {
            $haystack .= ' '.(string) ($block['value'] ?? '');
        }

        $matched = false;

        foreach (self::CONTACT_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                $matched = true;
                break;
            }
        }

        return [
            'rule_code' => 'forbid_contact_patterns',
            'passed' => ! $matched,
            'hard' => false,
        ];
    }

    private function checkReserveRatio(ReviewContentDTO $content, ReviewPolicy $policy): ?array
    {
        $multiplier = (int) $policy->deterministicRule('reserve_must_not_exceed_starting_multiplier', 0);

        if ($multiplier <= 0) {
            return null;
        }

        $starting = (int) ($content->structuredFacts['starting_amount_minor'] ?? 0);
        $reserve = $content->structuredFacts['reserve_amount_minor'] ?? null;

        if ($reserve === null || $starting <= 0) {
            return null;
        }

        return [
            'rule_code' => 'reserve_must_not_exceed_starting_multiplier',
            'passed' => (int) $reserve <= $starting * $multiplier,
            'hard' => true,
        ];
    }
}

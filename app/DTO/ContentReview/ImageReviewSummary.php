<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;

final readonly class ImageReviewSummary extends BaseContentReviewDTO
{
    public function __construct(
        public bool $analysisEnabled,
        public int $total,
        public int $selected,
        public int $sent,
        public int $analyzed,
        public int $cacheHits,
        public int $failed,
        public int $unscreened,
        public array $checks,
        public array $failures,
    ) {}

    public static function disabled(int $total): self
    {
        return new self(false, $total, 0, 0, 0, 0, 0, 0, [], []);
    }

    public static function build(int $total, array $prepared, array $checks): self
    {
        $ready = array_values(array_filter($prepared, static fn (PreparedImage $image): bool => $image->isReady()));
        $failures = array_values(array_filter($prepared, static fn (PreparedImage $image): bool => ! $image->isReady()));

        $cacheHits = 0;
        $analyzed = 0;
        $entries = [];

        foreach ($checks as $check) {
            if (! $check instanceof ImageCheckResult) {
                continue;
            }

            $check->fromCache ? $cacheHits++ : $analyzed++;
            $entries[] = $check->toArray();
        }

        $scoredRefs = array_map(static fn (array $entry): string => (string) $entry['ref'], $entries);

        $unscreened = 0;

        foreach ($ready as $image) {
            if (! in_array($image->ref, $scoredRefs, true)) {
                $unscreened++;
            }
        }

        return new self(
            true,
            $total,
            count($prepared),
            count($ready) - $cacheHits,
            $analyzed,
            $cacheHits,
            count($failures),
            $unscreened,
            $entries,
            array_map(static fn (PreparedImage $image): array => [
                'ref' => $image->ref,
                'failure_code' => (string) $image->failureCode,
            ], $failures),
        );
    }

    public static function fromArray(mixed $stored): self
    {
        if (! is_array($stored)) {
            return self::disabled(0);
        }

        $counts = is_array($stored['counts'] ?? null) ? $stored['counts'] : [];

        return new self(
            ($stored['analysis_enabled'] ?? false) === true,
            (int) ($counts['total'] ?? 0),
            (int) ($counts['selected'] ?? 0),
            (int) ($counts['sent'] ?? 0),
            (int) ($counts['analyzed'] ?? 0),
            (int) ($counts['cache_hits'] ?? 0),
            (int) ($counts['failed'] ?? 0),
            (int) ($counts['unscreened'] ?? 0),
            is_array($stored['checks'] ?? null) ? array_values(array_filter($stored['checks'], 'is_array')) : [],
            is_array($stored['failures'] ?? null) ? array_values(array_filter($stored['failures'], 'is_array')) : [],
        );
    }

    public function blockingReasons(): array
    {
        $reasons = [];

        if ($this->failed > 0) {
            $reasons[] = 'image_preparation_failed';
        }

        if ($this->unscreened > 0) {
            $reasons[] = 'image_not_screened';
        }

        return $reasons;
    }

    public function approvalBlockingReasons(): array
    {
        foreach ($this->checks as $check) {
            $verdict = ImageCheckVerdict::tryFrom((string) ($check['verdict'] ?? ''));

            if ($verdict === null || ! $verdict->allowsAutomaticApproval()) {
                return ['image_flagged'];
            }
        }

        return [];
    }

    public function worstRiskLevel(): ?ReviewRiskLevel
    {
        $worst = null;

        foreach ($this->checks as $check) {
            $level = ReviewRiskLevel::tryFrom((string) ($check['risk_level'] ?? ''));

            if ($level === null) {
                continue;
            }

            if ($worst === null || ! $level->isAtMost($worst)) {
                $worst = $level;
            }
        }

        return $worst;
    }

    public function toArray(): array
    {
        return [
            'analysis_enabled' => $this->analysisEnabled,
            'counts' => [
                'total' => $this->total,
                'selected' => $this->selected,
                'sent' => $this->sent,
                'analyzed' => $this->analyzed,
                'cache_hits' => $this->cacheHits,
                'failed' => $this->failed,
                'unscreened' => $this->unscreened,
            ],
            'checks' => $this->checks,
            'failures' => $this->failures,
        ];
    }
}

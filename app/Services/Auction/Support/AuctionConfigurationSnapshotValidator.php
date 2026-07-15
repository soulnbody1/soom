<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotIncompleteException;
use App\Models\Auction\AuctionTermsVersion;

final class AuctionConfigurationSnapshotValidator
{
    private const SELLER_POLICY_KEYS = [
        'unsold',
        'completed',
        'seller_cancellation_before_start',
        'seller_cancellation_after_start',
        'admin_cancellation_platform_fault',
        'admin_cancellation_seller_fault',
        'admin_cancellation_neutral',
        'admin_cancellation_fraud_or_compliance',
        'system_cancellation_platform_fault',
        'system_cancellation_seller_fault',
        'system_cancellation_neutral',
        'winner_default',
        'seller_breach',
        'dispute_complete',
        'dispute_cancel',
        'dispute_resume_handover',
    ];

    private const DISPOSITIONS = ['refund', 'forfeit', 'partial_forfeit', 'keep_held', 'manual_review', 'no_action'];

    public function assertValid(array $data): void
    {
        $errors = $this->validate($data);

        if ($errors !== []) {
            throw new AuctionConfigurationSnapshotIncompleteException($errors);
        }
    }

    public function validate(array $data): array
    {
        $errors = [];

        foreach ($this->requiredKeys() as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $errors[] = "{$key}: required";
            }
        }

        foreach ($this->nonNegativeIntegerKeys() as $key) {
            if (! $this->isIntegerLike($data[$key] ?? null) || (int) $data[$key] < 0) {
                $errors[] = "{$key}: must be a non-negative integer";
            }
        }

        foreach (['winner_payment_deadline_minutes', 'handover_deadline_minutes'] as $key) {
            if (! $this->isIntegerLike($data[$key] ?? null) || (int) $data[$key] <= 0) {
                $errors[] = "{$key}: must be positive";
            }
        }

        $candidateLimit = $data['alternative_candidate_limit'] ?? null;
        if (! $this->isIntegerLike($candidateLimit) || (int) $candidateLimit < 0) {
            $errors[] = 'alternative_candidate_limit: must be a non-negative integer';
        } elseif (! empty($data['alternative_winner_enabled']) && (int) $candidateLimit <= 0) {
            $errors[] = 'alternative_candidate_limit: must be positive when alternative winner is enabled';
        }

        if (($data['platform_fee_type'] ?? null) === 'percentage') {
            $basisPoints = (int) ($data['platform_fee_value'] ?? -1);
            if ($basisPoints < 0 || $basisPoints > 10_000) {
                $errors[] = 'platform_fee_value: percentage must be between 0 and 10000 basis points';
            }
        } elseif (($data['platform_fee_type'] ?? null) !== 'fixed') {
            $errors[] = 'platform_fee_type: unknown';
        }

        if (($data['platform_fee_max_minor'] ?? null) !== null && (int) $data['platform_fee_min_minor'] > (int) $data['platform_fee_max_minor']) {
            $errors[] = 'platform_fee_min_minor: cannot be greater than max';
        }

        $sellerPolicy = (array) ($data['seller_deposit_policy'] ?? []);
        foreach (self::SELLER_POLICY_KEYS as $key) {
            if (! isset($sellerPolicy[$key]) || ! in_array($sellerPolicy[$key], self::DISPOSITIONS, true)) {
                $errors[] = "seller_deposit_policy.{$key}: unknown";
            }
        }

        $winnerPolicy = (array) ($data['winner_default_deposit_policy'] ?? []);
        if (! isset($winnerPolicy['disposition']) || ! in_array($winnerPolicy['disposition'], ['full_forfeit', 'partial_forfeit', 'refund', 'manual_review', 'no_action'], true)) {
            $errors[] = 'winner_default_deposit_policy.disposition: unknown';
        }
        if (($winnerPolicy['forfeit_amount_minor'] ?? 0) < 0) {
            $errors[] = 'winner_default_deposit_policy.forfeit_amount_minor: must be non-negative';
        }

        if (($data['terms_version_id'] ?? null) && ! AuctionTermsVersion::whereKey($data['terms_version_id'])->exists()) {
            $errors[] = 'terms_version_id: not found';
        }

        return $errors;
    }

    private function requiredKeys(): array
    {
        return [
            'auction_id',
            'source_configuration_version_id',
            'currency_code',
            'minimum_bid_increment_minor',
            'auto_extend_window_seconds',
            'auto_extend_duration_seconds',
            'maximum_extensions',
            'seller_deposit_required_minor',
            'seller_deposit_policy',
            'bidder_deposit_required_minor',
            'non_winner_deposit_hold_policy',
            'alternative_candidate_limit',
            'winner_payment_deadline_minutes',
            'handover_deadline_minutes',
            'platform_fee_type',
            'platform_fee_value',
            'platform_fee_min_minor',
            'winner_default_deposit_policy',
        ];
    }

    private function nonNegativeIntegerKeys(): array
    {
        return [
            'minimum_bid_increment_minor',
            'auto_extend_window_seconds',
            'auto_extend_duration_seconds',
            'maximum_extensions',
            'seller_deposit_required_minor',
            'bidder_deposit_required_minor',
            'platform_fee_value',
            'platform_fee_min_minor',
        ];
    }

    private function isIntegerLike(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

final class AuctionConfigurationSnapshotHasher
{
    private const UNHASHED_KEYS = [
        'id',
        'snapshot_hash',
        'created_by',
        'finalized_at',
        'created_at',
        'updated_at',
        'winner_payment_grace_period_minutes',
        'winner_payment_reminder_hours',
        'seller_deposit_deadline_minutes',
        'review_sla_minutes',
        'handover_reminder_hours',
    ];

    public function hash(array $data): string
    {
        foreach (self::UNHASHED_KEYS as $key) {
            unset($data[$key]);
        }

        return hash('sha256', json_encode($this->canonical($data), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonical(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        return $data;
    }
}

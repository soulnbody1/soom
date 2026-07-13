<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

final class AuctionConfigurationSnapshotHasher
{
    public function hash(array $data): string
    {
        unset($data['id'], $data['snapshot_hash'], $data['created_by'], $data['finalized_at'], $data['created_at'], $data['updated_at']);

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

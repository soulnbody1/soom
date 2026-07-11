<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionConfigurationVersion;
use Illuminate\Support\Carbon;

final class AuctionConfigurationRepository
{
    /**
     * Get the currently active configuration version.
     * Must be active, effective_from <= now, and effective_until null or > now.
     */
    public function getActiveConfiguration(): AuctionConfigurationVersion
    {
        $now = Carbon::now();

        $config = AuctionConfigurationVersion::where('is_active', true)
            ->where('published_at', '<=', $now)
            ->orderByDesc('version_number')
            ->first();

        if (! $config) {
            throw AuctionException::configurationRequired();
        }

        return $config;
    }

    /**
     * Find configuration by public_id.
     */
    public function findByPublicId(string $publicId): ?AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::where('public_id', $publicId)->first();
    }

    /**
     * Create a new configuration version.
     */
    public function create(array $attributes): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::create($attributes);
    }
}

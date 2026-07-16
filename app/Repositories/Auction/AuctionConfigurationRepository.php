<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionConfigurationVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AuctionConfigurationRepository
{
    /**
     * All configuration versions, newest version first, for the admin dashboard.
     * Used by AuctionConfigurationController::index.
     */
    public function list(): Collection
    {
        return AuctionConfigurationVersion::with('creator:id,name')
            ->orderByDesc('version_number')
            ->get();
    }

    /**
     * Next append-only version number.
     * Used by CreateConfigurationVersionAction.
     */
    public function nextVersionNumber(): int
    {
        return ((int) AuctionConfigurationVersion::max('version_number')) + 1;
    }

    /**
     * Get the currently active configuration version.
     * Must be active, effective_from <= now, and effective_until null or > now.
     */
    public function getActiveConfiguration(): AuctionConfigurationVersion
    {
        $now = Carbon::now();

        $config = AuctionConfigurationVersion::where('is_active', true)
            ->where('published_at', '<=', $now)
            ->lockForUpdate()
            ->orderByDesc('version_number')
            ->first();

        if (! $config) {
            throw AuctionException::configurationRequired();
        }

        return $config;
    }

    /**
     * Create a new configuration version.
     */
    public function create(array $attributes): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::create($attributes);
    }
}

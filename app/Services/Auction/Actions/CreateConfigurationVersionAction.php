<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\AuctionConfigurationVersion;
use App\Repositories\Auction\AuctionConfigurationRepository;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class CreateConfigurationVersionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionConfigurationRepository $configurations,
    ) {}

    /**
     * Append-only: existing versions are never mutated or deactivated here.
     * getActiveConfiguration() resolves the highest active published version,
     * so publishing a new version supersedes older ones without touching them.
     */
    public function execute(array $configuration, bool $publish, int $creatorId): AuctionConfigurationVersion
    {
        return $this->transaction->run(function () use ($configuration, $publish, $creatorId): AuctionConfigurationVersion {
            return $this->configurations->create([
                'version_number' => $this->configurations->nextVersionNumber(),
                'configuration' => $configuration,
                'is_active' => $publish,
                'created_by' => $creatorId,
                'published_at' => $publish ? Carbon::now() : null,
            ]);
        });
    }
}

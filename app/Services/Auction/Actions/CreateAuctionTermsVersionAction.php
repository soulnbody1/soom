<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\AuctionTermsVersion;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class CreateAuctionTermsVersionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionTermsRepository $terms,
    ) {}

    public function execute(string $title, string $body, bool $publish, int $creatorId): AuctionTermsVersion
    {
        return $this->transaction->run(function () use ($title, $body, $publish, $creatorId): AuctionTermsVersion {
            $next = $this->terms->nextVersionNumber();

            if ($publish) {
                $this->terms->deactivateAllVersions();
            }

            return $this->terms->createVersion([
                'version_number' => $next,
                'title' => $title,
                'body' => $body,
                'is_active' => $publish,
                'created_by' => $creatorId,
                'published_at' => $publish ? Carbon::now() : null,
            ]);
        });
    }
}

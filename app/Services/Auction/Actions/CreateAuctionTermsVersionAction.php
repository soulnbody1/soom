<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\AuctionTermsVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CreateAuctionTermsVersionAction
{
    public function execute(string $title, string $body, bool $publish, int $creatorId): AuctionTermsVersion
    {
        return DB::transaction(function () use ($title, $body, $publish, $creatorId): AuctionTermsVersion {
            $next = ((int) AuctionTermsVersion::max('version_number')) + 1;

            if ($publish) {
                AuctionTermsVersion::query()->update(['is_active' => false]);
            }

            return AuctionTermsVersion::create([
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

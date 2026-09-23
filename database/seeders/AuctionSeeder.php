<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use Database\Seeders\Concerns\SeedsInDefaultMarket;
use Illuminate\Database\Seeder;

final class AuctionSeeder extends Seeder
{
    use SeedsInDefaultMarket;

    public function run(): void
    {
        $this->inDefaultMarket(fn () => $this->seed());
    }

    private function seed(): void
    {
        PaymentMethod::firstOrCreate(
            ['code' => 'manual_bank_transfer'],
            [
                'name' => 'Manual bank transfer',
                'instructions' => 'Upload a private payment receipt. Admin finance review is required.',
                'requires_manual_review' => true,
                'is_active' => true,
            ]
        );

        AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            [
                'title' => 'Standard auction terms',
                'body' => 'Bids are binding. Deposits may be applied, refunded, or forfeited according to auction policy.',
                'is_active' => true,
                'published_at' => now(),
            ]
        );
    }
}

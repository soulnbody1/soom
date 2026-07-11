<?php

declare(strict_types=1);

namespace Database\Factories\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class AuctionFactory extends Factory
{
    protected $model = Auction::class;

    public function definition(): array
    {
        $starts = now()->addHour();
        $ends = $starts->copy()->addDays(3);

        return [
            'seller_id' => User::factory(),
            'category_id' => Category::query()->first()?->id ?? Category::factory(),
            'country_id' => Country::query()->first()?->id ?? Country::factory(),
            'terms_version_id' => AuctionTermsVersion::query()->where('is_active', true)->first()?->id,
            'currency_code' => 'JOD',
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status' => AuctionStatus::Draft,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => 15_000,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'starts_at' => $starts,
            'original_ends_at' => $ends,
            'ends_at' => $ends,
        ];
    }

    public function live(): self
    {
        return $this->state(fn () => [
            'status' => AuctionStatus::Live,
            'starts_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'original_ends_at' => now()->addHour(),
            'ends_at' => now()->addHour(),
            'published_at' => now()->subHours(2),
        ]);
    }
}

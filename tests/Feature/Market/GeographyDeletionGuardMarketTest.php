<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Ad;
use App\Models\City;
use App\Models\Market;
use App\Models\State;
use App\Models\User;
use App\Services\Location\GeographyDeletionGuard;
use App\Support\Market\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GeographyDeletionGuardMarketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('spaces');
    }

    private function egyptianCity(): City
    {
        $eg = Market::query()->where('code', 'EG')->firstOrFail();
        $state = State::query()->create(['country_id' => $eg->country_id, 'name' => 'القاهرة']);

        return City::query()->create(['state_id' => $state->id, 'name' => 'مدينة نصر']);
    }

    public function test_a_city_an_egyptian_ad_depends_on_cannot_be_deleted_while_filtering_jordan(): void
    {
        $eg = Market::query()->where('code', 'EG')->firstOrFail();
        $jo = Market::query()->where('code', 'JO')->firstOrFail();
        $city = $this->egyptianCity();
        $seller = User::factory()->create();

        app(MarketContext::class)->runInMarket($eg, function () use ($city, $eg, $seller): void {
            Ad::factory()->create([
                'user_id' => $seller->id,
                'country_id' => $eg->country_id,
                'state_id' => $city->state_id,
                'city_id' => $city->id,
                'currency_code' => 'EGP',
            ]);
        });

        $this->expectException(ValidationException::class);

        app(MarketContext::class)->runInMarket(
            $jo,
            fn () => app(GeographyDeletionGuard::class)->assertCityDeletable($city->id)
        );
    }

    public function test_an_unreferenced_city_stays_deletable(): void
    {
        $jo = Market::query()->where('code', 'JO')->firstOrFail();
        $city = $this->egyptianCity();

        app(MarketContext::class)->runInMarket(
            $jo,
            fn () => app(GeographyDeletionGuard::class)->assertCityDeletable($city->id)
        );

        $this->assertTrue(true);
    }
}

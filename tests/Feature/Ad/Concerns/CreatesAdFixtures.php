<?php

declare(strict_types=1);

namespace Tests\Feature\Ad\Concerns;

use App\Models\Ad;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use App\Support\Market\MarketContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared fixture builders for the Ad characterization suite.
 *
 * Every ad in a test shares one country/state/city trio so location filters are
 * deterministic and the fixtures stay cheap — AdFactory would otherwise create a
 * fresh geo chain per ad.
 */
trait CreatesAdFixtures
{
    private ?Country $fixtureCountry = null;

    private ?State $fixtureState = null;

    private ?City $fixtureCity = null;

    protected function country(): Country
    {
        return $this->fixtureCountry ??= Country::query()->where('iso2', 'JO')->firstOrFail();
    }

    protected function state(): State
    {
        return $this->fixtureState ??= State::factory()->create(['country_id' => $this->country()->id]);
    }

    protected function city(): City
    {
        return $this->fixtureCity ??= City::factory()->create(['state_id' => $this->state()->id]);
    }

    protected function category(?int $parentId = null, ?string $name = null): Category
    {
        $category = Category::factory()->create(array_filter([
            'parent_id' => $parentId,
            'name' => $name,
        ], static fn ($value): bool => $value !== null));

        $category->markets()->attach(app(MarketContext::class)->marketId(), [
            'is_visible' => true,
            'display_order' => (int) $category->display_order,
        ]);

        return $category;
    }

    protected function adUser(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
        ]);
    }

    /**
     * @return Collection<int, Ad>
     */
    protected function makeAds(int $count, array $overrides = []): Collection
    {
        $ads = Ad::factory()
            ->count($count)
            ->atLocation($this->country(), $this->state(), $this->city())
            ->create($overrides);

        $marketId = app(MarketContext::class)->marketId();
        $ads->pluck('category')->filter()->unique('id')->each(
            fn (Category $category) => $category->markets()->syncWithoutDetaching([
                $marketId => [
                    'is_visible' => true,
                    'display_order' => (int) $category->display_order,
                ],
            ])
        );

        return $ads;
    }

    protected function makeAd(array $overrides = []): Ad
    {
        return $this->makeAds(1, $overrides)->first();
    }
}

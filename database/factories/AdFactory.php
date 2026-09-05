<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ad>
 */
final class AdFactory extends Factory
{
    protected $model = Ad::class;

    public function definition(): array
    {
        $country = Country::factory();
        $state = State::factory();
        $city = City::factory();

        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'price' => $this->faker->randomFloat(2, 1, 99_999),
            'country_id' => $country,
            'state_id' => $state,
            'city_id' => $city,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /**
     * Pin the ad to an existing country/state/city trio so a whole fixture set
     * shares one location instead of creating a new one per ad.
     */
    public function atLocation(Country $country, ?State $state = null, ?City $city = null): self
    {
        return $this->state(fn (): array => [
            'country_id' => $country->id,
            'state_id' => $state?->id,
            'city_id' => $city?->id,
        ]);
    }

    public function featured(): self
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function trashed(): self
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}

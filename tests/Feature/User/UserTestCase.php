<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\Ad;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsQueryCount;
use Tests\TestCase;

abstract class UserTestCase extends TestCase
{
    use AssertsQueryCount;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    protected function requireMysql(string $reason = 'the user search emits MySQL-only SQL'): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: '.$reason.'.');
        }
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function member(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function ad(User $owner): Ad
    {
        return Ad::create([
            'user_id' => $owner->id,
            'category_id' => $this->category()->id,
            'title' => 'إعلان',
            'description' => 'وصف الإعلان.',
            'price' => 100,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
        ]);
    }

    protected function category(): Category
    {
        return Category::firstOrCreate(['name' => 'user-category'], ['display_order' => 0]);
    }

    protected function country(): Country
    {
        return Country::firstOrCreate(['name' => 'user-country'], ['code' => 'USR']);
    }

    protected function state(): State
    {
        return State::firstOrCreate(['name' => 'user-state'], ['country_id' => $this->country()->id]);
    }

    protected function city(): City
    {
        return City::firstOrCreate(['name' => 'user-city'], ['state_id' => $this->state()->id]);
    }

    /**
     * @return list<string>
     */
    protected function userResourceKeys(): array
    {
        return [
            'id', 'name', 'phone', 'logo', 'birth_date', 'gender', 'address',
            'country_id', 'state_id', 'city_id',
            'allow_ad_notifications', 'is_blocked', 'hasAds', 'created_at',
        ];
    }
}

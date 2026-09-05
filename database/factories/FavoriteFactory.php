<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Favorite>
 */
final class FavoriteFactory extends Factory
{
    protected $model = Favorite::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ad_id' => Ad::factory(),
        ];
    }
}

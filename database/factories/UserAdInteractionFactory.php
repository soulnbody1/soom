<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\User;
use App\Models\UserAdInteraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAdInteraction>
 */
final class UserAdInteractionFactory extends Factory
{
    protected $model = UserAdInteraction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ad_id' => Ad::factory(),
            'action' => $this->faker->randomElement(['click', 'save']),
        ];
    }
}

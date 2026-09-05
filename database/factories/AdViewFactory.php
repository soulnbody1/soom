<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdView>
 */
final class AdViewFactory extends Factory
{
    protected $model = AdView::class;

    public function definition(): array
    {
        return [
            'ad_id' => Ad::factory(),
            'user_id' => User::factory(),
            'viewed_at' => now(),
        ];
    }
}

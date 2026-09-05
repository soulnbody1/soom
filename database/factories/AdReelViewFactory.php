<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdReel;
use App\Models\AdReelView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdReelView>
 */
final class AdReelViewFactory extends Factory
{
    protected $model = AdReelView::class;

    public function definition(): array
    {
        return [
            'ad_reel_id' => AdReel::factory(),
            'user_id' => User::factory(),
            'viewed_at' => now(),
        ];
    }
}

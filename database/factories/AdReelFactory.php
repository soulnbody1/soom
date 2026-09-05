<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdReel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdReel>
 */
final class AdReelFactory extends Factory
{
    protected $model = AdReel::class;

    public function definition(): array
    {
        return [
            'ad_id' => Ad::factory(),
            'video_path' => 'reels/'.$this->faker->uuid().'.mp4',
            'thumbnail_path' => 'reels/'.$this->faker->uuid().'.jpg',
            'duration' => $this->faker->numberBetween(5, 60),
        ];
    }
}

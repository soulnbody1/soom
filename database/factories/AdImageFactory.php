<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdImage>
 */
final class AdImageFactory extends Factory
{
    protected $model = AdImage::class;

    public function definition(): array
    {
        return [
            'ad_id' => Ad::factory(),
            'image_path' => 'ads/'.$this->faker->uuid().'.jpg',
        ];
    }
}

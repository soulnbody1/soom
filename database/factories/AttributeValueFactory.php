<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ad;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttributeValue>
 */
final class AttributeValueFactory extends Factory
{
    protected $model = AttributeValue::class;

    public function definition(): array
    {
        return [
            'ad_id' => Ad::factory(),
            'attribute_id' => Attribute::factory(),
            'value' => $this->faker->word(),
        ];
    }
}

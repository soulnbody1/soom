<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeOption;
use Illuminate\Database\Eloquent\Factories\Factory;

final class AttributeOptionFactory extends Factory
{
    protected $model = AttributeOption::class;

    public function definition(): array
    {
        $value = $this->faker->unique()->word();

        return [
            'attribute_id' => Attribute::factory(),
            'value' => $value,
            'label' => $value,
            'parent_option_id' => null,
        ];
    }
}

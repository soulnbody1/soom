<?php

declare(strict_types=1);

namespace Database\Factories\Auction;

use App\Models\Auction\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

final class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'name' => 'Manual bank transfer',
            'code' => 'bank_transfer_'.$this->faker->unique()->numberBetween(1, 999999),
            'instructions' => 'Upload a clear receipt image or PDF.',
            'requires_manual_review' => true,
            'is_active' => true,
        ];
    }
}

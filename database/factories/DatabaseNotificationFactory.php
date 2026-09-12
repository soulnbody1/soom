<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Notifications\NewAdNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

final class DatabaseNotificationFactory extends Factory
{
    protected $model = DatabaseNotification::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => NewAdNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => User::factory(),
            'data' => [
                'ad_id' => $this->faker->numberBetween(1, 1000),
                'title' => $this->faker->sentence(3),
                'category_id' => $this->faker->numberBetween(1, 50),
                'message' => $this->faker->sentence(),
                'created_at' => now()->toDateTimeString(),
            ],
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
        ]);
    }

    public function auction(array $overrides = []): static
    {
        return $this->state(fn (): array => [
            'type' => \App\Notifications\AuctionOutboxNotification::class,
            'data' => [
                'event_id' => (string) Str::ulid(),
                'event_type' => 'auction.outbid',
                'auction_id' => (string) Str::ulid(),
                'auction_title' => $this->faker->sentence(3),
                'screen' => 'auction_details',
                'title' => $this->faker->sentence(3),
                'message' => $this->faker->sentence(),
                ...$overrides,
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Notification\Concerns;

use App\Models\User;
use Database\Factories\DatabaseNotificationFactory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait CreatesNotificationFixtures
{
    protected function notifiableUser(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    protected function notifications(): DatabaseNotificationFactory
    {
        return DatabaseNotificationFactory::new();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    protected function seedNotifications(User $user, int $unread, int $read = 0): Collection
    {
        $rows = new Collection;

        for ($index = 0; $index < $unread; $index++) {
            $rows->push($this->notifications()->forUser($user)->create([
                'created_at' => now()->subMinutes($index),
            ]));
        }

        for ($index = 0; $index < $read; $index++) {
            $rows->push($this->notifications()->forUser($user)->read()->create([
                'created_at' => now()->subMinutes($unread + $index),
            ]));
        }

        return $rows;
    }

    protected function seedAuctionNotification(User $user, array $overrides = []): DatabaseNotification
    {
        return $this->notifications()->forUser($user)->auction($overrides)->create();
    }

    protected function bulkSeedNotifications(User $user, int $total, int $readRatio = 0): void
    {
        $now = now();
        $rows = [];

        for ($index = 0; $index < $total; $index++) {
            $isRead = $readRatio > 0 && $index % $readRatio === 0;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => \App\Notifications\NewAdNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'ad_id' => $index,
                    'title' => 'seeded '.$index,
                    'category_id' => 1,
                    'message' => 'seeded notification body',
                ]),
                'read_at' => $isRead ? $now : null,
                'created_at' => $now->copy()->subSeconds($index),
                'updated_at' => $now,
            ];

            if (count($rows) === 1000) {
                DatabaseNotification::insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DatabaseNotification::insert($rows);
        }
    }
}

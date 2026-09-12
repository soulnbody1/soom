<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use Tests\Concerns\AssertsQueryCount;
use Tests\Feature\Notification\Concerns\CreatesNotificationFixtures;
use Tests\TestCase;

abstract class NotificationTestCase extends TestCase
{
    use AssertsQueryCount;
    use CreatesNotificationFixtures;

    protected function requireMysql(string $reason): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: '.$reason.'.');
        }
    }

    /**
     * @return list<string>
     */
    protected function notificationKeys(): array
    {
        return [
            'id', 'type', 'event_type', 'screen', 'title', 'message',
            'auction_id', 'ad_id', 'category_id',
            'your_bid', 'new_bid', 'time_remaining',
            'amount', 'advertiser_name', 'advertiser_phone',
            'has_winner', 'final_price', 'winner_name', 'winner_phone',
            'image', 'read_at', 'created_at',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Jobs\SendFcmNotification;

final class PushDispatcher
{
    public function __construct(private readonly DeviceTokenRegistry $devices) {}

    public function toUser(int $userId, string $title, string $body, array $data = []): void
    {
        $this->dispatchAll($this->devices->tokensFor($userId), $title, $body, $data);
    }

    public function toUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        foreach ($this->devices->tokensForMany($userIds) as $tokens) {
            $this->dispatchAll($tokens, $title, $body, $data);
        }
    }

    public function toUsersInMarket(array $userIds, int $marketId, string $title, string $body, array $data = []): void
    {
        foreach ($this->devices->tokensForManyInMarket($userIds, $marketId) as $tokens) {
            $this->dispatchAll($tokens, $title, $body, $data);
        }
    }

    private function dispatchAll(array $tokens, string $title, string $body, array $data): void
    {
        foreach ($tokens as $token) {
            SendFcmNotification::dispatch($token, $title, $body, $data);
        }
    }
}

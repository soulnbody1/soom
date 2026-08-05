<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Models\Auction\OutboxMessage;

interface OutboxNotifier
{
    public function notify(OutboxMessage $message): void;
}

<?php

declare(strict_types=1);

namespace App\Services\Message\Support;

use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Throwable;

class ChatPresence
{
    public function bothPresent(int $userId, int $partnerId): bool
    {
        if (! config('chat.presence_enabled')) {
            return false;
        }

        $channel = 'presence-chat.'.min($userId, $partnerId).'.'.max($userId, $partnerId);

        try {
            $response = $this->client()->get("/channels/{$channel}/users");

            $present = collect((array) ($response->users ?? []))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            return in_array($userId, $present, true) && in_array($partnerId, $present, true);
        } catch (Throwable $exception) {
            Log::warning('Chat presence lookup failed: '.$exception->getMessage(), [
                'user_id' => $userId,
                'partner_id' => $partnerId,
            ]);

            return false;
        }
    }

    private function client(): Pusher
    {
        $options = (array) config('broadcasting.connections.pusher.options');
        $options['timeout'] = (int) config('chat.presence_timeout_seconds');

        return new Pusher(
            (string) config('broadcasting.connections.pusher.key'),
            (string) config('broadcasting.connections.pusher.secret'),
            (string) config('broadcasting.connections.pusher.app_id'),
            $options
        );
    }
}

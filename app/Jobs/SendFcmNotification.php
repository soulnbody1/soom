<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FCMService;
use App\Services\Notification\DeviceTokenRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 30;

    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data = []
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(FCMService $fcm, DeviceTokenRegistry $tokens): void
    {
        if (! $fcm->isConfigured()) {
            Log::warning('FCM is not configured; dropping push instead of retrying.');

            $this->delete();

            return;
        }

        try {
            $fcm->sendToToken($this->token, $this->title, $this->body, $this->data);
        } catch (Throwable $exception) {
            if ($fcm->isDeadTokenFailure($exception)) {
                $tokens->forget($this->token);

                Log::info('FCM token rejected and removed.', ['token' => $this->maskedToken()]);

                $this->delete();

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('FCM delivery failed after retries: '.$exception->getMessage(), [
            'token' => $this->maskedToken(),
        ]);
    }

    private function maskedToken(): string
    {
        return substr($this->token, 0, 12).'...';
    }
}

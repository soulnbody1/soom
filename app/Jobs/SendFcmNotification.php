<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FCMService;
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
    ) {}

    public function handle(): void
    {
        app(FCMService::class)->sendToToken(
            $this->token,
            $this->title,
            $this->body,
            $this->data
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('FCM delivery failed after retries: '.$exception->getMessage(), [
            'token' => substr($this->token, 0, 12).'...',
        ]);
    }
}

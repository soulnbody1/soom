<?php

declare(strict_types=1);

namespace App\Services\Outbox;

use App\Jobs\SendFcmNotification;
use App\Models\Auction\OutboxMessage;
use App\Models\ContentReview\ContentReview;
use App\Models\User;
use App\Notifications\ContentReviewAdminNotification;
use App\Services\Auction\Notifications\OutboxNotifier;
use App\Services\ContentReview\Notifications\AdminAlertRecipientResolver;
use App\Services\ContentReview\Notifications\ContentReviewNotificationCatalog;
use RuntimeException;

final class ContentReviewOutboxNotifier implements OutboxNotifier
{
    public function __construct(private readonly AdminAlertRecipientResolver $recipients) {}

    public function notify(OutboxMessage $message): void
    {
        if (! ContentReviewNotificationCatalog::supports($message->event_type)) {
            throw new RuntimeException("Unsupported content review outbox event: {$message->event_type}");
        }

        if ($message->aggregate_type !== ContentReview::class) {
            throw new RuntimeException("Unsupported content review outbox aggregate: {$message->aggregate_type}");
        }

        if (! ContentReviewNotificationCatalog::hasAdmin($message->event_type)) {
            return;
        }

        $payload = is_array($message->payload) ? $message->payload : [];
        $scopeKey = $this->scopeKey($message->event_type, $payload);

        if (! $this->recipients->shouldAlert($message->event_type, $scopeKey)) {
            return;
        }

        $title = (string) __("content_review.notifications.{$message->event_type}.title");
        $body = (string) __("content_review.notifications.{$message->event_type}.body", [
            'subject' => (string) ($payload['subject_label'] ?? __('content_review.messages.no_review')),
        ]);

        foreach ($this->recipients->recipients() as $user) {
            $this->deliver($user, $message, $title, $body, $payload);
        }
    }

    private function deliver(User $user, OutboxMessage $message, string $title, string $body, array $payload): void
    {
        $eventId = (string) $message->event_id;

        if ($this->alreadyNotified($user, $eventId)) {
            return;
        }

        $data = [
            'screen' => ContentReviewNotificationCatalog::screen($message->event_type),
            'title' => $title,
            'message' => $body,
            'review_id' => $payload['review_id'] ?? null,
            'subject_type' => $payload['subject_type'] ?? null,
            'subject_reference' => $payload['subject_reference'] ?? null,
            'status' => $payload['status'] ?? null,
            'outcome' => $payload['outcome'] ?? null,
            'error_code' => $payload['error_code'] ?? null,
        ];

        $user->notify(new ContentReviewAdminNotification($eventId, $message->event_type, $data));

        if ($user->fcm_token) {
            SendFcmNotification::dispatch($user->fcm_token, $title, $body, [
                'event_type' => $message->event_type,
                'screen' => $data['screen'],
            ]);
        }
    }

    private function alreadyNotified(User $user, string $eventId): bool
    {
        return $user->notifications()
            ->where('type', ContentReviewAdminNotification::class)
            ->where('data->event_id', $eventId)
            ->exists();
    }

    private function scopeKey(string $eventType, array $payload): ?string
    {
        if (ContentReviewNotificationCatalog::scope($eventType) === ContentReviewNotificationCatalog::SCOPE_ALERT) {
            $code = $payload['alert_code'] ?? null;

            return is_string($code) && $code !== '' ? $code : null;
        }

        return $this->subjectKey($payload);
    }

    private function subjectKey(array $payload): ?string
    {
        $type = $payload['subject_type'] ?? null;
        $reference = $payload['subject_reference'] ?? null;

        if (! is_string($type) || $type === '' || ! is_scalar($reference) || (string) $reference === '') {
            return null;
        }

        return $type.':'.$reference;
    }
}

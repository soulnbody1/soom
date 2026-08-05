<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Notifications;

final class ContentReviewNotificationCatalog
{
    public const TOPIC = 'content_review.events';

    public const SCREEN_REVIEW_QUEUE = 'admin_review_queue';

    public const SCREEN_REVIEW_HEALTH = 'admin_review_health';

    /**
     * admin: delivered to employees holding content_review.view.
     * subject_scoped: rate limited per (event, subject); otherwise rate limited per event globally.
     *
     * A failed attempt is recorded but not alerted: in a decision-applying mode it is
     * always followed by content_review.escalated, and alerting on both would notify
     * twice for one piece of news.
     */
    private const EVENTS = [
        'content_review.queued' => ['admin' => false, 'subject_scoped' => true, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.completed' => ['admin' => false, 'subject_scoped' => true, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.escalated' => ['admin' => true, 'subject_scoped' => true, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.auto_decided' => ['admin' => true, 'subject_scoped' => true, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.stale' => ['admin' => true, 'subject_scoped' => true, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.failed' => ['admin' => false, 'subject_scoped' => true, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.provider_unavailable' => ['admin' => true, 'subject_scoped' => false, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.circuit_open' => ['admin' => true, 'subject_scoped' => false, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.budget_exhausted' => ['admin' => true, 'subject_scoped' => false, 'screen' => self::SCREEN_REVIEW_HEALTH],
    ];

    public static function supports(string $eventType): bool
    {
        return isset(self::EVENTS[$eventType]);
    }

    public static function hasAdmin(string $eventType): bool
    {
        return self::EVENTS[$eventType]['admin'] ?? false;
    }

    public static function isSubjectScoped(string $eventType): bool
    {
        return self::EVENTS[$eventType]['subject_scoped'] ?? false;
    }

    public static function screen(string $eventType): string
    {
        return self::EVENTS[$eventType]['screen'] ?? self::SCREEN_REVIEW_QUEUE;
    }

    /**
     * @return array<int, string>
     */
    public static function eventTypes(): array
    {
        return array_keys(self::EVENTS);
    }
}

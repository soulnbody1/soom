<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Notifications;

final class ContentReviewNotificationCatalog
{
    public const TOPIC = 'content_review.events';

    public const SCREEN_REVIEW_QUEUE = 'admin_review_queue';

    public const SCREEN_REVIEW_HEALTH = 'admin_review_health';

    public const SCOPE_SUBJECT = 'subject';

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_ALERT = 'alert';

    /**
     * admin: delivered to every admin.
     * scope: the rate limiting key — per (event, subject), per event globally, or per
     * (event, alert code) for the shared recovery event.
     *
     * A failed attempt is recorded but not alerted: in a decision-applying mode it is
     * always followed by content_review.escalated, and alerting on both would notify
     * twice for one piece of news.
     */
    private const EVENTS = [
        'content_review.queued' => ['admin' => false, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.completed' => ['admin' => false, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.escalated' => ['admin' => true, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.auto_decided' => ['admin' => true, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.stale' => ['admin' => true, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.confirmed' => ['admin' => false, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.overridden' => ['admin' => false, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.failed' => ['admin' => false, 'scope' => self::SCOPE_SUBJECT, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.provider_unavailable' => ['admin' => true, 'scope' => self::SCOPE_GLOBAL, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.circuit_open' => ['admin' => true, 'scope' => self::SCOPE_GLOBAL, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.budget_exhausted' => ['admin' => true, 'scope' => self::SCOPE_GLOBAL, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.queue_delay_high' => ['admin' => true, 'scope' => self::SCOPE_GLOBAL, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.invalid_output_spike' => ['admin' => true, 'scope' => self::SCOPE_GLOBAL, 'screen' => self::SCREEN_REVIEW_HEALTH],
        'content_review.escalation_backlog' => ['admin' => true, 'scope' => self::SCOPE_GLOBAL, 'screen' => self::SCREEN_REVIEW_QUEUE],
        'content_review.recovered' => ['admin' => true, 'scope' => self::SCOPE_ALERT, 'screen' => self::SCREEN_REVIEW_HEALTH],
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
        return self::scope($eventType) === self::SCOPE_SUBJECT;
    }

    public static function scope(string $eventType): string
    {
        return self::EVENTS[$eventType]['scope'] ?? self::SCOPE_GLOBAL;
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

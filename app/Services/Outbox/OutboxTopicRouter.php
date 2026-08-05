<?php

declare(strict_types=1);

namespace App\Services\Outbox;

use App\Models\Auction\OutboxMessage;
use App\Services\Auction\Notifications\AuctionOutboxNotifier;
use App\Services\Auction\Notifications\OutboxNotifier;
use RuntimeException;

final class OutboxTopicRouter implements OutboxNotifier
{
    public const TOPIC_AUCTION = 'auction.events';

    public const TOPIC_CONTENT_REVIEW = 'content_review.events';

    public function __construct(
        private readonly AuctionOutboxNotifier $auction,
        private readonly ContentReviewOutboxNotifier $contentReview,
    ) {}

    public function notify(OutboxMessage $message): void
    {
        match ($message->topic) {
            self::TOPIC_AUCTION => $this->auction->notify($message),
            self::TOPIC_CONTENT_REVIEW => $this->contentReview->notify($message),
            default => throw new RuntimeException("Unsupported outbox topic: {$message->topic}"),
        };
    }

    public static function supports(string $topic): bool
    {
        return in_array($topic, [self::TOPIC_AUCTION, self::TOPIC_CONTENT_REVIEW], true);
    }
}

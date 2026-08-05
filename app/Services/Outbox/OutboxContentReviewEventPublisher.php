<?php

declare(strict_types=1);

namespace App\Services\Outbox;

use App\Domain\Auction\Enums\OutboxStatus;
use App\DTO\Auction\CreateOutboxMessageDTO;
use App\Models\ContentReview\ContentReview;
use App\Repositories\Auction\AuctionOutboxRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Notifications\ContentReviewNotificationCatalog;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class OutboxContentReviewEventPublisher implements ContentReviewEventPublisher
{
    public function __construct(
        private readonly AuctionOutboxRepository $outbox,
        private readonly ReviewSubjectRegistry $registry,
    ) {}

    public function publish(ContentReview $review, string $eventType, array $payload = []): void
    {
        if (! ContentReviewNotificationCatalog::supports($eventType)) {
            return;
        }

        $reference = $this->subjectReference($review);

        $this->store($eventType, (int) $review->id, array_replace([
            'review_id' => (string) $review->public_id,
            'subject_type' => $review->subject_type->value,
            'subject_reference' => $reference,
            'subject_label' => $reference,
            'status' => $review->status->value,
            'outcome' => $review->outcome?->value,
            'reason_code' => $review->reason_code,
            'error_code' => $review->error_code?->value,
            'mode' => $review->mode->value,
        ], $payload));
    }

    private function subjectReference(ContentReview $review): ?string
    {
        if (! $this->registry->supports($review->subject_type)) {
            return null;
        }

        return $this->registry->for($review->subject_type)->subjectReference((int) $review->subject_id);
    }

    public function publishOperational(string $eventType, array $payload = []): void
    {
        if (! ContentReviewNotificationCatalog::supports($eventType)) {
            return;
        }

        $this->store($eventType, 0, $payload);
    }

    private function store(string $eventType, int $aggregateId, array $payload): void
    {
        $this->outbox->store(new CreateOutboxMessageDTO(
            (string) Str::ulid(),
            ContentReviewNotificationCatalog::TOPIC,
            $eventType,
            ContentReview::class,
            $aggregateId,
            $payload,
            OutboxStatus::Pending,
            Carbon::now(),
        ));
    }
}

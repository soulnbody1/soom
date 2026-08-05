<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Contracts;

use App\Models\ContentReview\ContentReview;

interface ContentReviewEventPublisher
{
    public function publish(ContentReview $review, string $eventType, array $payload = []): void;

    public function publishOperational(string $eventType, array $payload = []): void;
}

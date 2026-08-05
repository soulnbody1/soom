<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Contracts;

use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;

interface ContentReviewProvider
{
    public function name(): string;

    public function analyze(ProviderReviewRequest $request): ProviderReviewResponse;
}

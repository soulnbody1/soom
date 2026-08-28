<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\ResolvedProvider;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;

final class ProviderResolver
{
    public function __construct(
        private readonly ContentReviewProviderFactory $providers,
        private readonly ProviderSelectionResolver $selection,
    ) {}

    public function resolve(ReviewSettings $settings): ResolvedProvider
    {
        $provider = $this->providers->make($this->selection->provider($settings));

        return new ResolvedProvider(
            $provider,
            $provider->name(),
            $this->selection->model($settings),
            $this->selection->descriptor($settings),
        );
    }
}

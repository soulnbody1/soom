<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use Illuminate\Contracts\Container\Container;

final class ContentReviewProviderFactory
{
    private const PROVIDERS = [
        'fake' => FakeContentReviewProvider::class,
        'anthropic' => AnthropicContentReviewProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function make(?string $name = null): ContentReviewProvider
    {
        $name = $name ?? (string) config('content_review.provider', 'fake');
        $class = self::PROVIDERS[$name] ?? null;

        if ($class === null) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
        }

        return $this->container->make($class);
    }

    public function available(): array
    {
        return array_keys(self::PROVIDERS);
    }
}

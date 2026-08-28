<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use Illuminate\Contracts\Foundation\Application;

final class ContentReviewProviderFactory
{
    private const FAKE = 'fake';

    private const PROVIDERS = [
        'fake' => FakeContentReviewProvider::class,
        'anthropic' => AnthropicContentReviewProvider::class,
        'openrouter' => OpenRouterContentReviewProvider::class,
        'gemini' => GeminiContentReviewProvider::class,
    ];

    public function __construct(private readonly Application $container) {}

    public function make(?string $name = null): ContentReviewProvider
    {
        $name = $name ?? (string) config('content_review.provider', 'fake');
        $class = self::PROVIDERS[$name] ?? null;

        if ($class === null || ! $this->isPermitted($name)) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
        }

        return $this->container->make($class);
    }

    private function isPermitted(string $name): bool
    {
        if ($name !== self::FAKE) {
            return true;
        }

        return ! $this->container->environment('production')
            || config('content_review.allow_fake_provider') === true;
    }

    public function available(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function isConfigured(string $name): bool
    {
        try {
            return $this->make($name)->isConfigured();
        } catch (ContentReviewProviderException) {
            return false;
        }
    }
}

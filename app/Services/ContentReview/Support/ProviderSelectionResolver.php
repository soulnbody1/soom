<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\ProviderModelDescriptor;

final class ProviderSelectionResolver
{
    public function __construct(private readonly ProviderModelCatalog $catalog) {}

    public function provider(ReviewSettings $settings): string
    {
        return $settings->provider() ?? (string) config('content_review.provider', 'fake');
    }

    public function model(ReviewSettings $settings): string
    {
        $provider = $this->provider($settings);

        foreach ([$settings->model(), config('content_review.model')] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $this->catalog->has($provider, $candidate)) {
                return $candidate;
            }
        }

        $default = $this->catalog->defaultModel($provider);

        if ($default !== null) {
            return $default;
        }

        $requested = $settings->model() ?? config('content_review.model');

        return is_string($requested) ? $requested : '';
    }

    public function descriptor(ReviewSettings $settings): ?ProviderModelDescriptor
    {
        return $this->catalog->descriptor($this->provider($settings), $this->model($settings));
    }
}

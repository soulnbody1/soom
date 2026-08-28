<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Services\ContentReview\Contracts\ContentReviewProvider;

final readonly class ResolvedProvider extends BaseContentReviewDTO
{
    public function __construct(
        public ContentReviewProvider $provider,
        public string $name,
        public string $model,
        public ?ProviderModelDescriptor $descriptor,
    ) {}

    public function toArray(): array
    {
        return [
            'provider' => $this->name,
            'model' => $this->model,
            'supports_images' => $this->descriptor?->supportsImages,
        ];
    }
}

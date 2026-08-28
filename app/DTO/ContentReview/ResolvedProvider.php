<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Services\ContentReview\Contracts\ContentReviewProvider;

/**
 * The provider a call will go to, together with the model and the capabilities it was chosen
 * for. These three have to agree — pricing, image support and the structured-output strategy
 * are all read off the descriptor for this exact model — so they are resolved once and travel
 * together rather than being asked for separately at each use.
 */
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

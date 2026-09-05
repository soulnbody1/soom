<?php

declare(strict_types=1);

namespace App\DTO\Ad;

use Illuminate\Http\UploadedFile;

final readonly class AdWriteInputDTO
{
    public function __construct(
        public array $fields,
        public array $attributes,
        public array $images,
        public ?UploadedFile $reelVideo,
    ) {}

    public static function fromValidated(array $validated): self
    {
        $attributes = $validated['attributes'] ?? [];
        $images = $validated['images'] ?? [];
        $reelVideo = $validated['reel_video'] ?? null;

        unset($validated['attributes'], $validated['images'], $validated['reel_video']);

        return new self($validated, $attributes, $images, $reelVideo);
    }
}

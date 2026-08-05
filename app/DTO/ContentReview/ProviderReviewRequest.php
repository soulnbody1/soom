<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;

final readonly class ProviderReviewRequest extends BaseContentReviewDTO
{
    public function __construct(
        public ReviewableSubjectType $subjectType,
        public string $policyInstructions,
        public array $resultSchema,
        public array $textBlocks,
        public array $structuredFacts,
        public array $images,
        public string $model,
        public int $maxOutputTokens,
        public int $timeoutSeconds,
        public array $locales,
    ) {}

    public function toArray(): array
    {
        return [
            'subject_type' => $this->subjectType->value,
            'model' => $this->model,
            'max_output_tokens' => $this->maxOutputTokens,
            'timeout_seconds' => $this->timeoutSeconds,
            'locales' => $this->locales,
            'text_block_count' => count($this->textBlocks),
            'image_count' => count($this->images),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;

final readonly class ReviewContentDTO extends BaseContentReviewDTO
{
    public function __construct(
        public ReviewableSubjectType $subjectType,
        public int $subjectId,
        public array $textBlocks,
        public array $structuredFacts,
        public array $images,
        public array $imageSources = [],
    ) {}

    public function toHashable(): array
    {
        return [
            'subject_type' => $this->subjectType->value,
            'text_blocks' => $this->textBlocks,
            'structured_facts' => $this->structuredFacts,
            'images' => $this->images,
        ];
    }

    public function imageCount(): int
    {
        return count($this->images);
    }

    public function textFor(string $field): ?string
    {
        foreach ($this->textBlocks as $block) {
            if (($block['field'] ?? null) === $field) {
                return (string) ($block['value'] ?? '');
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return $this->toHashable();
    }
}

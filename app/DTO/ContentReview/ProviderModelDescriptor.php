<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\StructuredOutputStrategy;

final readonly class ProviderModelDescriptor extends BaseContentReviewDTO
{
    public function __construct(
        public string $id,
        public int $inputMicros,
        public int $outputMicros,
        public bool $supportsImages,
        public StructuredOutputStrategy $structured,
    ) {}

    public static function fromArray(string $id, mixed $entry): ?self
    {
        if ($id === '' || ! is_array($entry)) {
            return null;
        }

        $input = $entry['input'] ?? null;
        $output = $entry['output'] ?? null;

        if (! is_int($input) || ! is_int($output) || $input < 0 || $output < 0) {
            return null;
        }

        $structured = StructuredOutputStrategy::tryFrom(
            (string) ($entry['structured'] ?? StructuredOutputStrategy::Tool->value)
        );

        if ($structured === null) {
            return null;
        }

        return new self($id, $input, $output, ($entry['vision'] ?? true) === true, $structured);
    }

    public function isFree(): bool
    {
        return $this->inputMicros === 0 && $this->outputMicros === 0;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'supports_images' => $this->supportsImages,
            'structured' => $this->structured->value,
            'input_micros' => $this->inputMicros,
            'output_micros' => $this->outputMicros,
            'free' => $this->isFree(),
        ];
    }
}

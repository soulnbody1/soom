<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\MessageBag;

final class AdAttributeValidator
{
    private const OPTION_TYPES = ['select', 'checkbox', 'radio'];

    public function __construct(private readonly CategoryAttributeResolver $resolver) {}

    public function validate(?int $categoryId, array $submitted): MessageBag
    {
        $errors = new MessageBag;

        if ($categoryId === null) {
            return $errors;
        }

        $allowed = $this->resolver->resolve($categoryId)->keyBy('id');
        $seen = [];

        foreach ($submitted as $index => $entry) {
            if (! is_array($entry) || ! isset($entry['id'])) {
                continue;
            }

            $attributeId = (int) $entry['id'];
            $attribute = $allowed->get($attributeId);

            if ($attribute === null) {
                $errors->add("attributes.{$index}.id", 'هذه الخاصية لا تتبع الفئة المختارة.');

                continue;
            }

            $seen[$attributeId] = true;
            $values = array_values(array_filter(
                (array) ($entry['value'] ?? []),
                static fn ($value): bool => $value !== null && $value !== ''
            ));

            $this->validateCardinality($attribute, $values, (string) $index, $errors);
            $this->validateValues($attribute, $values, (string) $index, $errors);
        }

        $this->validateRequired($allowed, $seen, $errors);

        return $errors;
    }

    private function validateCardinality(Attribute $attribute, array $values, string $index, MessageBag $errors): void
    {
        if (! $attribute->is_multiple && count($values) > 1) {
            $errors->add("attributes.{$index}.value", "الخاصية «{$attribute->name}» تقبل قيمة واحدة فقط.");
        }
    }

    private function validateValues(Attribute $attribute, array $values, string $index, MessageBag $errors): void
    {
        if ($values === []) {
            return;
        }

        if (in_array($attribute->type, self::OPTION_TYPES, true)) {
            $this->validateAgainstOptions($attribute, $values, $index, $errors);

            return;
        }

        if ($attribute->type === 'between') {
            $this->validateAgainstRange($attribute, $values, $index, $errors);

            return;
        }

        if ($attribute->type === 'number') {
            foreach ($values as $value) {
                if (! is_numeric($value)) {
                    $errors->add("attributes.{$index}.value", "الخاصية «{$attribute->name}» تقبل أرقامًا فقط.");

                    return;
                }
            }
        }
    }

    private function validateAgainstOptions(Attribute $attribute, array $values, string $index, MessageBag $errors): void
    {
        $allowed = $attribute->options->pluck('value')->map(static fn ($value): string => (string) $value)->all();

        foreach ($values as $value) {
            if (! in_array((string) $value, $allowed, true)) {
                $errors->add(
                    "attributes.{$index}.value",
                    "القيمة «{$value}» ليست ضمن خيارات الخاصية «{$attribute->name}»."
                );
            }
        }
    }

    private function validateAgainstRange(Attribute $attribute, array $values, string $index, MessageBag $errors): void
    {
        $bounds = $attribute->options
            ->pluck('value')
            ->filter(static fn ($value): bool => is_numeric($value))
            ->map(static fn ($value): float => (float) $value)
            ->sort()
            ->values();

        if ($bounds->count() < 2) {
            return;
        }

        $min = $bounds->first();
        $max = $bounds->last();

        foreach ($values as $value) {
            if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
                $errors->add(
                    "attributes.{$index}.value",
                    "الخاصية «{$attribute->name}» تقبل رقمًا بين {$min} و{$max}."
                );
            }
        }
    }

    /**
     * @param  Collection<int, Attribute>  $allowed
     * @param  array<int, bool>  $seen
     */
    private function validateRequired(Collection $allowed, array $seen, MessageBag $errors): void
    {
        foreach ($allowed as $attribute) {
            if ($attribute->is_required && ! isset($seen[(int) $attribute->id])) {
                $errors->add('attributes', "الخاصية «{$attribute->name}» مطلوبة لهذه الفئة.");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

final class StrictJsonSchemaAdapter
{
    private const UNSUPPORTED_KEYWORDS = [
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'minLength',
        'maxLength',
        'minItems',
        'maxItems',
        'pattern',
        'format',
        'default',
    ];

    public function apply(array $schema): array
    {
        foreach (self::UNSUPPORTED_KEYWORDS as $keyword) {
            unset($schema[$keyword]);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->apply($schema['items']);
        }

        if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
            return $schema;
        }

        $required = $schema['required'] ?? [];
        $required = is_array($required) ? array_values(array_filter($required, 'is_string')) : [];

        foreach ($schema['properties'] as $name => $property) {
            if (! is_array($property)) {
                continue;
            }

            $property = $this->apply($property);

            $schema['properties'][$name] = in_array((string) $name, $required, true)
                ? $property
                : $this->nullable($property);
        }

        $schema['required'] = array_map('strval', array_keys($schema['properties']));
        $schema['additionalProperties'] = false;

        return $schema;
    }

    private function nullable(array $property): array
    {
        if (isset($property['enum']) && is_array($property['enum']) && ! in_array(null, $property['enum'], true)) {
            $property['enum'] = array_merge(array_values($property['enum']), [null]);
        }

        $type = $property['type'] ?? null;

        if (is_string($type) && $type !== 'null') {
            $property['type'] = [$type, 'null'];
        } elseif (is_array($type) && ! in_array('null', $type, true)) {
            $property['type'] = array_values([...$type, 'null']);
        }

        return $property;
    }
}

<?php

declare(strict_types=1);

use App\Services\ContentReview\Support\StrictJsonSchemaAdapter;

function sourceSchema(): array
{
    return [
        'type' => 'object',
        'required' => ['recommendation'],
        'additionalProperties' => false,
        'properties' => [
            'recommendation' => ['type' => 'string', 'enum' => ['approve', 'reject']],
            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'summary_en' => ['type' => 'string', 'maxLength' => 600],
            'violations' => [
                'type' => 'array',
                'maxItems' => 20,
                'items' => [
                    'type' => 'object',
                    'required' => ['code'],
                    'additionalProperties' => false,
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'evidence' => ['type' => 'string', 'maxLength' => 200],
                    ],
                ],
            ],
        ],
    ];
}

test('every property becomes required and the unsupported keywords are dropped', function (): void {
    $strict = (new StrictJsonSchemaAdapter)->apply(sourceSchema());

    expect($strict['required'])->toBe(['recommendation', 'confidence', 'summary_en', 'violations'])
        ->and($strict['additionalProperties'])->toBeFalse()
        ->and($strict['properties']['recommendation']['type'])->toBe('string')
        ->and($strict['properties']['recommendation']['enum'])->toBe(['approve', 'reject'])
        ->and($strict['properties']['confidence'])->not->toHaveKey('minimum')
        ->and($strict['properties']['confidence'])->not->toHaveKey('maximum')
        ->and($strict['properties']['summary_en'])->not->toHaveKey('maxLength')
        ->and($strict['properties']['violations'])->not->toHaveKey('maxItems');
});

test('an optional property is widened to accept null', function (): void {
    $strict = (new StrictJsonSchemaAdapter)->apply(sourceSchema());

    expect($strict['properties']['confidence']['type'])->toBe(['integer', 'null'])
        ->and($strict['properties']['violations']['items']['required'])->toBe(['code', 'evidence'])
        ->and($strict['properties']['violations']['items']['properties']['evidence']['type'])->toBe(['string', 'null']);
});

test('an optional enum also admits null', function (): void {
    $strict = (new StrictJsonSchemaAdapter)->apply([
        'type' => 'object',
        'required' => [],
        'properties' => ['risk_level' => ['type' => 'string', 'enum' => ['low', 'high']]],
    ]);

    expect($strict['properties']['risk_level']['type'])->toBe(['string', 'null'])
        ->and($strict['properties']['risk_level']['enum'])->toBe(['low', 'high', null]);
});

test('the source schema is never rewritten in place', function (): void {
    $source = sourceSchema();

    (new StrictJsonSchemaAdapter)->apply($source);

    expect($source)->toBe(sourceSchema());
});

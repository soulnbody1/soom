<?php

declare(strict_types=1);

use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use App\Services\ContentReview\Support\ProviderModelCatalog;
use App\Services\ContentReview\Support\ProviderSelectionResolver;

function catalog(): ProviderModelCatalog
{
    return app(ProviderModelCatalog::class);
}

function reviewSettings(array $settings = []): ReviewSettings
{
    return new ReviewSettings($settings);
}

function selection(): ProviderSelectionResolver
{
    return app(ProviderSelectionResolver::class);
}

test('every registered driver has a catalog entry and every entry is well formed', function (): void {
    $catalog = catalog();
    $drivers = app(ContentReviewProviderFactory::class)->available();

    expect($drivers)->not->toBeEmpty();

    foreach ($drivers as $provider) {
        expect($catalog->models($provider))->not->toBeEmpty("{$provider} has no catalogued model");

        foreach ($catalog->models($provider) as $model) {
            $descriptor = $catalog->descriptor($provider, $model);

            expect($descriptor->id)->toBe($model)
                ->and($descriptor->inputMicros)->toBeInt()->toBeGreaterThanOrEqual(0)
                ->and($descriptor->outputMicros)->toBeInt()->toBeGreaterThanOrEqual(0)
                ->and($descriptor->structured)->toBeInstanceOf(StructuredOutputStrategy::class);
        }
    }
});

test('every provider default model is one of its own models', function (): void {
    $catalog = catalog();

    foreach (app(ContentReviewProviderFactory::class)->available() as $provider) {
        $default = $catalog->defaultModel($provider);

        expect($default)->toBeString()
            ->and($catalog->has($provider, (string) $default))->toBeTrue("{$provider} defaults to a model it does not offer");
    }
});

test('the pricing metadata is versioned', function (): void {
    expect(config('content_review.pricing.version'))->toBeString()->not->toBe('')
        ->and(config('content_review.pricing.currency'))->toBe('USD');
});

test('a malformed catalog entry is refused rather than defaulted', function (): void {
    config()->set('content_review.providers.broken', [
        'default_model' => 'a',
        'models' => [
            'a' => ['input' => '3000000', 'output' => 15_000_000],
            'b' => ['input' => -1, 'output' => 1],
            'c' => ['input' => 1, 'output' => 1, 'structured' => 'telepathy'],
            'd' => ['input' => 1, 'output' => 1],
        ],
    ]);

    $catalog = catalog();

    expect($catalog->has('broken', 'a'))->toBeFalse()
        ->and($catalog->has('broken', 'b'))->toBeFalse()
        ->and($catalog->has('broken', 'c'))->toBeFalse()
        ->and($catalog->has('broken', 'd'))->toBeTrue()
        ->and($catalog->defaultModel('broken'))->toBe('d');
});

test('an unknown provider has an empty catalog instead of throwing', function (): void {
    expect(catalog()->models('nonexistent'))->toBe([])
        ->and(catalog()->descriptor('nonexistent', 'anything'))->toBeNull()
        ->and(catalog()->defaultModel('nonexistent'))->toBeNull();
});

test('a model defaults to tool calling and to vision unless the catalog says otherwise', function (): void {
    config()->set('content_review.providers.terse', [
        'models' => ['m' => ['input' => 0, 'output' => 0]],
    ]);

    $descriptor = catalog()->descriptor('terse', 'm');

    expect($descriptor->structured)->toBe(StructuredOutputStrategy::Tool)
        ->and($descriptor->supportsImages)->toBeTrue()
        ->and($descriptor->isFree())->toBeTrue();
});

test('the selection resolver keeps the published settings ahead of config', function (): void {
    config()->set('content_review.provider', 'fake');
    config()->set('content_review.model', 'claude-sonnet-5');

    expect(selection()->provider(reviewSettings()))->toBe('fake')
        ->and(selection()->model(reviewSettings()))->toBe('claude-sonnet-5')
        ->and(selection()->provider(reviewSettings(['provider' => 'openrouter'])))->toBe('openrouter')
        ->and(selection()->model(reviewSettings(['provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini'])))->toBe('openai/gpt-4o-mini');
});

test('a model that does not belong to the selected provider falls back to that provider default', function (): void {
    config()->set('content_review.provider', 'fake');
    config()->set('content_review.model', 'claude-sonnet-5');

    expect(selection()->model(reviewSettings(['provider' => 'openrouter', 'model' => 'claude-sonnet-5'])))
        ->toBe('google/gemini-2.5-flash');
});

test('the descriptor travels with the resolved provider and model', function (): void {
    $descriptor = selection()->descriptor(reviewSettings(['provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini']));

    expect($descriptor->id)->toBe('openai/gpt-4o-mini')
        ->and($descriptor->structured)->toBe(StructuredOutputStrategy::JsonSchema)
        ->and($descriptor->isFree())->toBeFalse();
});

test('the exposed catalog carries what an operator needs to choose a model', function (): void {
    $exposed = catalog()->toArray();

    expect($exposed)->toHaveKeys(['fake', 'anthropic', 'openrouter', 'gemini'])
        ->and($exposed['openrouter'][0])->toHaveKeys([
            'id', 'supports_images', 'structured', 'input_micros', 'output_micros', 'free',
        ]);

    foreach ($exposed as $models) {
        foreach ($models as $model) {
            expect(json_encode($model))->not->toContain('key')
                ->and(json_encode($model))->not->toContain('secret');
        }
    }
});

test('a versioned build the vendor resolved an alias to is still priced', function (): void {
    config()->set('content_review.providers.acme', [
        'default_model' => 'acme-1',
        'models' => [
            'acme-1' => ['input' => 1_000_000, 'output' => 2_000_000, 'vision' => true, 'structured' => 'tool'],
            'acme-1-lite' => ['input' => 100_000, 'output' => 200_000, 'vision' => true, 'structured' => 'tool'],
        ],
    ]);

    $costs = app(ProviderCostCalculator::class);
    $exact = $costs->costMicros('acme', 'acme-1', 1_000_000, 1_000_000);

    expect($costs->costMicros('acme', 'acme-1-20260101', 1_000_000, 1_000_000))->toBe($exact)

        ->and($costs->costMicros('acme', 'acme-1-lite-preview', 1_000_000, 1_000_000))
        ->toBe($costs->costMicros('acme', 'acme-1-lite', 1_000_000, 1_000_000))

        ->and($costs->costMicros('acme', 'acme-12', 1_000_000, 1_000_000))->toBeNull()

        ->and($costs->costMicros('acme', 'something-else', 1_000_000, 1_000_000))->toBeNull();
});

test('selection stays strict so a mistyped configured model is never silently repriced', function (): void {
    config()->set('content_review.providers.acme', [
        'default_model' => 'acme-1',
        'models' => ['acme-1' => ['input' => 1, 'output' => 1, 'vision' => true, 'structured' => 'tool']],
    ]);

    expect(catalog()->has('acme', 'acme-1-20260101'))->toBeFalse()
        ->and(catalog()->pricingDescriptor('acme', 'acme-1-20260101'))->not->toBeNull();
});

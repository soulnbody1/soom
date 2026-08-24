<?php

declare(strict_types=1);

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Providers\AnthropicContentReviewProvider;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Providers\OpenRouterContentReviewProvider;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const PROVIDER_TEST_API_KEY = 'sk-ant-api03-UNIQUESECRETVALUE-should-never-leak';

function providerRequest(array $overrides = []): ProviderReviewRequest
{
    return new ProviderReviewRequest(
        ReviewableSubjectType::Auction,
        $overrides['policyInstructions'] ?? 'POLICY',
        $overrides['resultSchema'] ?? ['type' => 'object'],
        $overrides['textBlocks'] ?? [['field' => 'title', 'locale' => 'ar', 'value' => 'سيارة']],
        $overrides['structuredFacts'] ?? ['starting_amount_minor' => 1000],
        $overrides['images'] ?? [],
        $overrides['model'] ?? 'claude-sonnet-5',
        $overrides['maxOutputTokens'] ?? 2000,
        $overrides['timeoutSeconds'] ?? 5,
        $overrides['locales'] ?? ['ar', 'en'],
    );
}

function providerPayload(array $overrides = []): array
{
    return array_replace([
        'recommendation' => 'approve',
        'confidence' => 91,
        'risk_level' => 'low',
        'requires_human_review' => false,
        'summary_ar' => 'محتوى سليم.',
        'summary_en' => 'Clean content.',
        'categories' => [],
        'violations' => [],
        'findings' => [],
        'policy_checks' => [],
        'missing_information' => [],
    ], $overrides);
}

function anthropicBody(array $overrides = []): array
{
    return array_replace([
        'id' => 'msg_01FAKE',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5',
        'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_01FAKE',
            'name' => 'record_content_review',
            'input' => providerPayload(),
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 500, 'output_tokens' => 120],
    ], $overrides);
}

function anthropicProvider(): AnthropicContentReviewProvider
{
    return app(AnthropicContentReviewProvider::class);
}

function expectProviderErrorCode(callable $callback, ContentReviewErrorCode $expected): ContentReviewProviderException
{
    try {
        $callback();
    } catch (ContentReviewProviderException $exception) {
        expect($exception->errorCode())->toBe($expected);

        return $exception;
    }

    throw new RuntimeException('Expected a ContentReviewProviderException with code '.$expected->value.'.');
}

beforeEach(function (): void {
    config()->set('services.anthropic.api_key', PROVIDER_TEST_API_KEY);
    config()->set('services.anthropic.base_url', 'https://api.anthropic.com');
    config()->set('services.anthropic.version', '2023-06-01');
    Http::preventStrayRequests();
});

test('a contract compliant response is mapped onto the provider response DTO', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(), 200, ['request-id' => 'req_01ABC'])]);

    $response = anthropicProvider()->analyze(providerRequest());

    expect($response->payload)->toBe(providerPayload())
        ->and($response->model)->toBe('claude-sonnet-5')
        ->and($response->inputTokens)->toBe(500)
        ->and($response->outputTokens)->toBe(120)
        ->and($response->providerRequestId)->toBe('req_01ABC')
        ->and($response->latencyMs)->toBeGreaterThanOrEqual(0);
});

test('the request carries the api key, the version header and the forced tool choice', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody())]);

    anthropicProvider()->analyze(providerRequest());

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', PROVIDER_TEST_API_KEY)
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $body['model'] === 'claude-sonnet-5'
            && $body['max_tokens'] === 2000
            && $body['tool_choice'] === ['type' => 'tool', 'name' => 'record_content_review']
            && $body['tools'][0]['name'] === 'record_content_review';
    });
});

test('a missing api key fails closed without contacting the provider', function (): void {
    config()->set('services.anthropic.api_key', '');
    Http::fake();

    expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        ContentReviewErrorCode::ProviderAuthFailed
    );

    Http::assertNothingSent();
});

test('http status codes map onto the closed error code set', function (int $status, ContentReviewErrorCode $code): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'nope']], $status)]);

    expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        $code
    );
})->with([
    'unauthorized' => [401, ContentReviewErrorCode::ProviderAuthFailed],
    'forbidden' => [403, ContentReviewErrorCode::ProviderAuthFailed],
    'request timeout' => [408, ContentReviewErrorCode::ProviderTimeout],
    'rate limited' => [429, ContentReviewErrorCode::ProviderRateLimited],
    'bad request' => [400, ContentReviewErrorCode::ProviderUnavailable],
    'server error' => [500, ContentReviewErrorCode::ProviderUnavailable],
    'bad gateway' => [502, ContentReviewErrorCode::ProviderUnavailable],
    'unavailable' => [503, ContentReviewErrorCode::ProviderUnavailable],
    'overloaded' => [529, ContentReviewErrorCode::ProviderUnavailable],
]);

test('a connection failure is reported as a provider timeout', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $exception = expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );

    expect($exception->isRetryable())->toBeTrue();
});

test('an unexpected transport failure is reported as provider unavailable', function (): void {
    Http::fake(fn () => throw new RuntimeException('tls handshake blew up'));

    expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        ContentReviewErrorCode::ProviderUnavailable
    );
});

test('a body that is not valid json is rejected as invalid structured output', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response('<html>gateway</html>', 200)]);

    expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
});

test('a response without the expected tool result is rejected', function (array $content): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['content' => $content]))]);

    expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
})->with([
    'no blocks at all' => [[]],
    'text only' => [[['type' => 'text', 'text' => 'here is the answer']]],
    'a different tool' => [[['type' => 'tool_use', 'name' => 'something_else', 'input' => ['recommendation' => 'approve']]]],
    'tool result without an object input' => [[['type' => 'tool_use', 'name' => 'record_content_review', 'input' => 'approve']]],
    'content is not a list' => [['unexpected' => true]],
]);

test('a structured payload that does not match the contract is surfaced to the validator, not swallowed', function (): void {
    $partial = ['recommendation' => 'approve'];

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['content' => [[
        'type' => 'tool_use',
        'name' => 'record_content_review',
        'input' => $partial,
    ]]]))]);

    $response = anthropicProvider()->analyze(providerRequest());

    expect($response->payload)->toBe($partial);
});

test('the model reported by the provider wins over the requested model', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['model' => 'claude-opus-5']))]);

    $response = anthropicProvider()->analyze(providerRequest(['model' => 'claude-sonnet-5']));

    expect($response->model)->toBe('claude-opus-5')
        ->and($response->costMicros)->toBe(5 * 500 + 25 * 120);
});

test('absent token usage yields null tokens and no invented cost', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['usage' => []]))]);

    $response = anthropicProvider()->analyze(providerRequest());

    expect($response->inputTokens)->toBeNull()
        ->and($response->outputTokens)->toBeNull()
        ->and($response->costMicros)->toBeNull();
});

test('present token usage produces an integer cost from the pricing configuration', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody())]);

    $response = anthropicProvider()->analyze(providerRequest());

    expect($response->costMicros)->toBe(1500 + 1800)
        ->and($response->costMicros)->toBeInt();
});

test('an unpriced model never invents a cost', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['model' => 'some-unlisted-model']))]);

    $response = anthropicProvider()->analyze(providerRequest());

    expect($response->model)->toBe('some-unlisted-model')
        ->and($response->costMicros)->toBeNull();
});

test('the provider request id falls back to the message id when the header is absent', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['id' => 'msg_01FALLBACK']))]);

    expect(anthropicProvider()->analyze(providerRequest())->providerRequestId)->toBe('msg_01FALLBACK');
});

test('the provider request id is null when neither the header nor the message id is usable', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody(['id' => '']))]);

    expect(anthropicProvider()->analyze(providerRequest())->providerRequestId)->toBeNull();
});

test('images are sent as base64 blocks and image entries without bytes are skipped', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicBody())]);

    anthropicProvider()->analyze(providerRequest(['images' => [
        ['mime' => 'image/png', 'bytes' => 'RAWBYTES'],
        ['mime' => 'image/png', 'bytes' => null],
    ]]));

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][0]['content'];

        return count($content) === 2
            && $content[0]['type'] === 'image'
            && $content[0]['source']['data'] === base64_encode('RAWBYTES')
            && $content[1]['type'] === 'text';
    });
});

test('the api key never reaches an exception message, its string form, or the logs', function (): void {
    Log::spy();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'x-api-key '.PROVIDER_TEST_API_KEY.' is invalid']], 401)]);

    $exception = expectProviderErrorCode(
        fn () => anthropicProvider()->analyze(providerRequest()),
        ContentReviewErrorCode::ProviderAuthFailed
    );

    expect($exception->getMessage())->not->toContain(PROVIDER_TEST_API_KEY)
        ->and((string) $exception)->not->toContain(PROVIDER_TEST_API_KEY)
        ->and($exception->getMessage())->toBe(ContentReviewErrorCode::ProviderAuthFailed->value);

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
});

test('the redactor strips credentials before an error message is ever stored', function (): void {
    $redactor = new ErrorMessageRedactor;

    $stored = $redactor->redactString('x-api-key: '.PROVIDER_TEST_API_KEY.' rejected by https://api.anthropic.com/v1/messages');

    expect($stored)->not->toContain(PROVIDER_TEST_API_KEY)
        ->and($stored)->not->toContain('api.anthropic.com')
        ->and(mb_strlen($stored))->toBeLessThanOrEqual(500);
});

test('stored error messages stay inside the column width', function (): void {
    $redactor = new ErrorMessageRedactor;

    expect(mb_strlen($redactor->redactString(str_repeat('e', 4000))))->toBe(500);
});

test('the cost calculator uses integer arithmetic only and refuses unknown models', function (): void {
    $calculator = app(ProviderCostCalculator::class);

    expect($calculator->version())->toBe('2026-06-24')
        ->and($calculator->costMicros('anthropic', 'gpt-not-a-model', 100, 100))->toBeNull()
        ->and($calculator->costMicros('anthropic', 'claude-sonnet-5', null, 100))->toBeNull()
        ->and($calculator->costMicros('anthropic', 'claude-sonnet-5', 100, null))->toBeNull()
        ->and($calculator->costMicros('anthropic', 'claude-sonnet-5', -1, 100))->toBeNull()
        ->and($calculator->costMicros('anthropic', 'claude-sonnet-5', 0, 0))->toBe(0)
        ->and($calculator->costMicros('anthropic', 'claude-sonnet-5', 1_000_000, 1_000_000))->toBe(18_000_000)
        ->and($calculator->costMicros('anthropic', 'claude-opus-5', 1_000_000, 1_000_000))->toBe(30_000_000)
        ->and($calculator->costMicros('anthropic', 'claude-haiku-4-5', 1, 1))->toBe(6);
});

test('a model is only priced under the provider that offers it', function (): void {
    $calculator = app(ProviderCostCalculator::class);

    expect($calculator->costMicros('openrouter', 'claude-sonnet-5', 100, 100))->toBeNull()
        ->and($calculator->costMicros('anthropic', 'claude-sonnet-5', 1_000_000, 1_000_000))->toBe(18_000_000)
        ->and($calculator->costMicros('openrouter', 'google/gemini-2.5-flash', 1_000_000, 1_000_000))->toBe(2_800_000);
});

test('the factory resolves the configured provider and rejects unknown ones', function (): void {
    $factory = app(ContentReviewProviderFactory::class);

    expect($factory->make('fake'))->toBeInstanceOf(FakeContentReviewProvider::class)
        ->and($factory->make('anthropic'))->toBeInstanceOf(AnthropicContentReviewProvider::class)
        ->and($factory->make('openrouter'))->toBeInstanceOf(OpenRouterContentReviewProvider::class)
        ->and($factory->available())->toBe(['fake', 'anthropic', 'openrouter'])
        ->and($factory->make('fake')->name())->toBe('fake')
        ->and($factory->make('anthropic')->name())->toBe('anthropic')
        ->and($factory->make('openrouter')->name())->toBe('openrouter');

    expectProviderErrorCode(
        fn () => $factory->make('nonexistent'),
        ContentReviewErrorCode::ProviderUnavailable
    );
});

test('provider readiness is answered by the driver, never by a name comparison', function (): void {
    $factory = app(ContentReviewProviderFactory::class);

    config()->set('services.anthropic.api_key', '');
    config()->set('services.openrouter.api_key', '');

    expect($factory->isConfigured('fake'))->toBeTrue()
        ->and($factory->isConfigured('anthropic'))->toBeFalse()
        ->and($factory->isConfigured('openrouter'))->toBeFalse()
        ->and($factory->isConfigured('nonexistent'))->toBeFalse();

    config()->set('services.anthropic.api_key', PROVIDER_TEST_API_KEY);
    config()->set('services.openrouter.api_key', 'sk-or-v1-configured');

    expect($factory->isConfigured('anthropic'))->toBeTrue()
        ->and($factory->isConfigured('openrouter'))->toBeTrue();
});

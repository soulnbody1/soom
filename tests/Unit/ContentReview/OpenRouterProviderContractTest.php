<?php

declare(strict_types=1);

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderModelDescriptor;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Providers\OpenRouterContentReviewProvider;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\UsdAmountConverter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const OPENROUTER_TEST_API_KEY = 'sk-or-v1-UNIQUESECRETVALUE-should-never-leak';

function openRouterDescriptor(StructuredOutputStrategy $strategy, bool $vision = true): ProviderModelDescriptor
{
    return new ProviderModelDescriptor('anthropic/claude-sonnet-4.5', 3_000_000, 15_000_000, $vision, $strategy);
}

function openRouterRequest(StructuredOutputStrategy $strategy = StructuredOutputStrategy::Tool, array $overrides = []): ProviderReviewRequest
{
    return new ProviderReviewRequest(
        ReviewableSubjectType::Auction,
        $overrides['policyInstructions'] ?? 'POLICY',
        $overrides['resultSchema'] ?? ['type' => 'object', 'properties' => ['recommendation' => ['type' => 'string']], 'required' => ['recommendation']],
        $overrides['textBlocks'] ?? [['field' => 'title', 'locale' => 'ar', 'value' => 'سيارة']],
        $overrides['structuredFacts'] ?? ['starting_amount_minor' => 1000],
        $overrides['images'] ?? [],
        $overrides['model'] ?? 'anthropic/claude-sonnet-4.5',
        $overrides['maxOutputTokens'] ?? 2000,
        $overrides['timeoutSeconds'] ?? 5,
        $overrides['locales'] ?? ['ar', 'en'],
        [],
        $overrides['descriptor'] ?? openRouterDescriptor($strategy),
    );
}

function openRouterPayload(array $overrides = []): array
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

function openRouterToolBody(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen-01FAKE',
        'model' => 'anthropic/claude-sonnet-4.5',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_01FAKE',
                    'type' => 'function',
                    'function' => [
                        'name' => 'record_content_review',
                        'arguments' => json_encode(openRouterPayload(), JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ],
        ]],
        'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 120],
    ], $overrides);
}

function openRouterContentBody(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen-01FAKE',
        'model' => 'google/gemini-2.5-flash',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => json_encode(openRouterPayload(), JSON_UNESCAPED_UNICODE),
            ],
        ]],
        'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 120],
    ], $overrides);
}

function openRouterProvider(): OpenRouterContentReviewProvider
{
    return app(OpenRouterContentReviewProvider::class);
}

function expectOpenRouterErrorCode(callable $callback, ContentReviewErrorCode $expected): ContentReviewProviderException
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
    config()->set('services.openrouter.api_key', OPENROUTER_TEST_API_KEY);
    config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    Http::preventStrayRequests();
});

test('a forced tool call is mapped onto the provider response DTO', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody(), 200, ['x-request-id' => 'req_01ABC'])]);

    $response = openRouterProvider()->analyze(openRouterRequest());

    expect($response->payload)->toBe(openRouterPayload())
        ->and($response->model)->toBe('anthropic/claude-sonnet-4.5')
        ->and($response->inputTokens)->toBe(500)
        ->and($response->outputTokens)->toBe(120)
        ->and($response->providerRequestId)->toBe('req_01ABC')
        ->and($response->latencyMs)->toBeGreaterThanOrEqual(0);
});

test('the request carries the bearer key and the forced function choice', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody())]);

    openRouterProvider()->analyze(openRouterRequest());

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('authorization', 'Bearer '.OPENROUTER_TEST_API_KEY)
            && $body['model'] === 'anthropic/claude-sonnet-4.5'
            && $body['max_tokens'] === 2000
            && $body['usage'] === ['include' => true]
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][0]['content'] === 'POLICY'
            && $body['tools'][0]['function']['name'] === 'record_content_review'
            && $body['tool_choice'] === ['type' => 'function', 'function' => ['name' => 'record_content_review']]
            && ! array_key_exists('response_format', $body);
    });
});

test('a json schema model sends a strict response format instead of tools', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterContentBody())]);

    $response = openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::JsonSchema));

    expect($response->payload)->toBe(openRouterPayload());

    Http::assertSent(function ($request): bool {
        $format = $request->data()['response_format'] ?? [];

        return ! array_key_exists('tools', $request->data())
            && $format['type'] === 'json_schema'
            && $format['json_schema']['strict'] === true
            && $format['json_schema']['name'] === 'record_content_review'
            && $format['json_schema']['schema']['additionalProperties'] === false;
    });
});

test('a json object model carries the schema in the prompt and tolerates a fenced answer', function (): void {
    $fenced = "```json\n".json_encode(openRouterPayload(), JSON_UNESCAPED_UNICODE)."\n```";

    Http::fake(['openrouter.ai/*' => Http::response(openRouterContentBody([
        'choices' => [['message' => ['role' => 'assistant', 'content' => $fenced]]],
    ]))]);

    $response = openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::JsonObject));

    expect($response->payload)->toBe(openRouterPayload());

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['response_format'] === ['type' => 'json_object']
            && str_starts_with($body['messages'][0]['content'], 'POLICY')
            && str_contains($body['messages'][0]['content'], '"type":"object"');
    });
});

test('images are sent as data urls and entries without bytes are skipped', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody())]);

    openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::Tool, [
        'images' => [
            ['ref' => 'img-1', 'mime' => 'image/png', 'bytes' => 'PNGBYTES'],
            ['ref' => 'img-2', 'mime' => 'image/png', 'bytes' => null],
        ],
    ]));

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][1]['content'];
        $urls = array_column(array_column($content, 'image_url'), 'url');

        return $urls === ['data:image/png;base64,'.base64_encode('PNGBYTES')]
            && $content[0] === ['type' => 'text', 'text' => 'IMAGE img-1']
            && array_column($content, 'type') === ['text', 'image_url', 'text'];
    });
});

test('a model without vision never receives an image part', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody())]);

    openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::Tool, [
        'descriptor' => openRouterDescriptor(StructuredOutputStrategy::Tool, vision: false),
        'images' => [],
    ]));

    Http::assertSent(function ($request): bool {
        $types = array_column($request->data()['messages'][1]['content'], 'type');

        return $types === ['text'];
    });
});

test('the cost reported by the provider is preferred over the rate card', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody([
        'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 120, 'cost' => 0.0000125],
    ]))]);

    $response = openRouterProvider()->analyze(openRouterRequest());

    expect($response->costMicros)->toBe(13);
});

test('the rate card prices the call when no cost is reported', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody())]);

    $response = openRouterProvider()->analyze(openRouterRequest());

    expect($response->costMicros)->toBe(3_300);
});

test('a zero rated model is priced at zero rather than left unpriced', function (): void {
    config()->set('content_review.providers.openrouter.models.vendor/gratis', [
        'input' => 0, 'output' => 0, 'vision' => true, 'structured' => 'json_schema',
    ]);

    Http::fake(['openrouter.ai/*' => Http::response(openRouterContentBody(['model' => 'vendor/gratis']))]);

    $response = openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::JsonSchema, [
        'model' => 'vendor/gratis',
        'descriptor' => new ProviderModelDescriptor('vendor/gratis', 0, 0, true, StructuredOutputStrategy::JsonSchema),
    ]));

    expect($response->costMicros)->toBe(0);
});

test('an answer cut off by the output ceiling is reported as truncation, not as garbage', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterContentBody([
        'choices' => [[
            'finish_reason' => 'length',
            'message' => ['role' => 'assistant', 'content' => null, 'reasoning' => 'thinking out loud...'],
        ]],
        'usage' => ['prompt_tokens' => 1371, 'completion_tokens' => 2000, 'cost' => 0],
    ]))]);

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::JsonSchema)),
        ContentReviewErrorCode::OutputTruncated
    );
});

test('an off contract answer that finished normally stays invalid structured output', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterContentBody([
        'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'User Safety: safe']]],
    ]))]);

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::JsonSchema)),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
});

test('a call that spent tokens before failing still reports what it spent', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterContentBody([
        'model' => 'openai/gpt-4o-mini',
        'choices' => [['finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => null]]],
        'usage' => ['prompt_tokens' => 1371, 'completion_tokens' => 2000, 'cost' => 0.0015],
    ]))]);

    $exception = expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::JsonSchema)),
        ContentReviewErrorCode::OutputTruncated
    );

    $metrics = $exception->metrics();

    expect($metrics)->not->toBeNull()
        ->and($metrics->model)->toBe('openai/gpt-4o-mini')
        ->and($metrics->inputTokens)->toBe(1371)
        ->and($metrics->outputTokens)->toBe(2000)
        ->and($metrics->costMicros)->toBe(1_500)
        ->and($metrics->finishReason)->toBe('length');
});

test('a failure that never reached the provider carries no metrics', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    $exception = expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );

    expect($exception->metrics())->toBeNull();
});

test('an uncatalogued model is left unpriced when nothing is reported', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(openRouterToolBody(['model' => 'vendor/not-a-model']))]);

    $response = openRouterProvider()->analyze(openRouterRequest(StructuredOutputStrategy::Tool, [
        'model' => 'vendor/not-a-model',
        'descriptor' => null,
    ]));

    expect($response->costMicros)->toBeNull();
});

test('a missing api key fails closed without contacting the provider', function (): void {
    config()->set('services.openrouter.api_key', '');
    Http::fake();

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        ContentReviewErrorCode::ProviderAuthFailed
    );

    Http::assertNothingSent();
    expect(openRouterProvider()->isConfigured())->toBeFalse();
});

test('http failures map onto stable error codes', function (int $status, ContentReviewErrorCode $expected): void {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'nope']], $status)]);

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        $expected
    );
})->with([
    'unauthorized' => [401, ContentReviewErrorCode::ProviderAuthFailed],
    'payment required' => [402, ContentReviewErrorCode::ProviderAuthFailed],
    'forbidden' => [403, ContentReviewErrorCode::ProviderAuthFailed],
    'bad request' => [400, ContentReviewErrorCode::ProviderUnavailable],
    'unprocessable' => [422, ContentReviewErrorCode::ProviderUnavailable],
    'timeout' => [408, ContentReviewErrorCode::ProviderTimeout],
    'gateway timeout' => [504, ContentReviewErrorCode::ProviderTimeout],
    'rate limited' => [429, ContentReviewErrorCode::ProviderRateLimited],
    'server error' => [500, ContentReviewErrorCode::ProviderUnavailable],
]);

test('an upstream error returned with a 200 is still a failure', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 502, 'message' => 'upstream down']], 200)]);

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        ContentReviewErrorCode::ProviderUnavailable
    );
});

test('a connection failure is reported as a timeout', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );
});

test('an answer that is not the agreed structure is rejected', function (array $body): void {
    Http::fake(['openrouter.ai/*' => Http::response($body)]);

    expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
})->with([
    'no choices' => [['id' => 'gen-1']],
    'no tool call' => [['choices' => [['message' => ['content' => 'here you go']]]]],
    'a different tool' => [['choices' => [['message' => ['tool_calls' => [['function' => ['name' => 'other', 'arguments' => '{}']]]]]]]],
    'arguments are not json' => [['choices' => [['message' => ['tool_calls' => [['function' => ['name' => 'record_content_review', 'arguments' => 'not json']]]]]]]],
]);

test('the api key never reaches an exception message, its string form, or the logs', function (): void {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('failed to connect with Bearer '.OPENROUTER_TEST_API_KEY));

    $exception = expectOpenRouterErrorCode(
        fn () => openRouterProvider()->analyze(openRouterRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );

    expect($exception->getMessage())->not->toContain(OPENROUTER_TEST_API_KEY)
        ->and((string) $exception)->not->toContain(OPENROUTER_TEST_API_KEY)
        ->and((new ErrorMessageRedactor)->redactString('Authorization: Bearer '.OPENROUTER_TEST_API_KEY))
        ->not->toContain(OPENROUTER_TEST_API_KEY);

    Log::shouldNotHaveReceived('error');
});

test('a usd amount becomes integer micros without a floating point step', function (): void {
    $converter = new UsdAmountConverter;

    expect($converter->toMicros('0'))->toBe(0)
        ->and($converter->toMicros('1'))->toBe(1_000_000)
        ->and($converter->toMicros('0.000001'))->toBe(1)
        ->and($converter->toMicros('0.0000125'))->toBe(13)
        ->and($converter->toMicros('0.0000124'))->toBe(12)
        ->and($converter->toMicros('2.5'))->toBe(2_500_000)
        ->and($converter->toMicros('12.345678912'))->toBe(12_345_679)
        ->and($converter->toMicros(''))->toBeNull()
        ->and($converter->toMicros('-1'))->toBeNull()
        ->and($converter->toMicros('1e-5'))->toBeNull()
        ->and($converter->toMicros(null))->toBeNull();
});

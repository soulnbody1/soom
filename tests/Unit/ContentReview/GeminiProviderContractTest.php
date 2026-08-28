<?php

declare(strict_types=1);

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderModelDescriptor;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Providers\GeminiContentReviewProvider;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\StrictJsonSchemaAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const GEMINI_TEST_API_KEY = 'AIza-UNIQUESECRETVALUE-should-never-leak';

const GEMINI_TEST_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

function geminiDescriptor(StructuredOutputStrategy $strategy, bool $vision = true): ProviderModelDescriptor
{
    return new ProviderModelDescriptor('gemini-3.6-flash', 750_000, 3_750_000, $vision, $strategy);
}

function geminiSchema(): array
{
    return [
        'type' => 'object',
        'required' => ['recommendation'],
        'additionalProperties' => false,
        'properties' => [
            'recommendation' => ['type' => 'string', 'enum' => ['approve', 'reject']],
            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'summary_ar' => ['type' => 'string', 'maxLength' => 600],
            'findings' => [
                'type' => 'array',
                'maxItems' => 20,
                'items' => [
                    'type' => 'object',
                    'required' => ['note'],
                    'additionalProperties' => false,
                    'properties' => ['note' => ['type' => 'string', 'maxLength' => 300]],
                ],
            ],
        ],
    ];
}

function geminiRequest(StructuredOutputStrategy $strategy = StructuredOutputStrategy::JsonSchema, array $overrides = []): ProviderReviewRequest
{
    return new ProviderReviewRequest(
        ReviewableSubjectType::Auction,
        $overrides['policyInstructions'] ?? 'POLICY',
        $overrides['resultSchema'] ?? geminiSchema(),
        $overrides['textBlocks'] ?? [['field' => 'title', 'locale' => 'ar', 'value' => 'سيارة']],
        $overrides['structuredFacts'] ?? ['starting_amount_minor' => 1000],
        $overrides['images'] ?? [],
        $overrides['model'] ?? 'gemini-3.6-flash',
        $overrides['maxOutputTokens'] ?? 2000,
        $overrides['timeoutSeconds'] ?? 5,
        $overrides['locales'] ?? ['ar', 'en'],
        [],
        array_key_exists('descriptor', $overrides) ? $overrides['descriptor'] : geminiDescriptor($strategy),
    );
}

function geminiPayload(array $overrides = []): array
{
    return array_replace([
        'recommendation' => 'approve',
        'confidence' => 91,
        'summary_ar' => 'محتوى سليم.',
        'findings' => [],
    ], $overrides);
}

function geminiUsage(array $overrides = []): array
{
    return array_replace(['promptTokenCount' => 500, 'candidatesTokenCount' => 120], $overrides);
}

function geminiTextBody(array $overrides = []): array
{
    return array_replace([
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => json_encode(geminiPayload(), JSON_UNESCAPED_UNICODE)]]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => geminiUsage(),
    ], $overrides);
}

function geminiFunctionCallBody(array $overrides = []): array
{
    return array_replace([
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [[
                'functionCall' => ['name' => 'record_content_review', 'args' => geminiPayload()],
            ]]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => geminiUsage(),
    ], $overrides);
}

function geminiErrorBody(string $status, string $message, int $code = 400): array
{
    return ['error' => ['code' => $code, 'message' => $message, 'status' => $status]];
}

function geminiProvider(): GeminiContentReviewProvider
{
    return app(GeminiContentReviewProvider::class);
}

function expectGeminiErrorCode(callable $callback, ContentReviewErrorCode $expected): ContentReviewProviderException
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
    config()->set('services.gemini.api_key', GEMINI_TEST_API_KEY);
    config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    config()->set('services.gemini.thinking_level', 'minimal');
    config()->set('services.gemini.thinking_budget', 0);
    Http::preventStrayRequests();
});

test('a json schema answer is mapped onto the provider response DTO', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody(), 200, ['x-goog-request-id' => 'req_01ABC'])]);

    $response = geminiProvider()->analyze(geminiRequest());

    expect($response->payload)->toBe(geminiPayload())
        ->and($response->model)->toBe('gemini-3.6-flash')
        ->and($response->inputTokens)->toBe(500)
        ->and($response->outputTokens)->toBe(120)
        ->and($response->costMicros)->toBe(825)
        ->and($response->providerRequestId)->toBe('req_01ABC')
        ->and($response->latencyMs)->toBeGreaterThanOrEqual(0);
});

test('the request carries the api key header and the generateContent path', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest());

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === GEMINI_TEST_ENDPOINT
            && $request->hasHeader('x-goog-api-key', GEMINI_TEST_API_KEY)
            && ! str_contains($request->url(), GEMINI_TEST_API_KEY)
            && $body['generationConfig']['maxOutputTokens'] === 2000
            && $body['systemInstruction']['parts'][0]['text'] === 'POLICY'
            && $body['contents'][0]['role'] === 'user'
            && ! array_key_exists('tools', $body);
    });
});

test('the result schema is normalised for strict validation before it is sent', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema));

    Http::assertSent(function ($request): bool {
        $config = $request->data()['generationConfig'];
        $schema = $config['responseJsonSchema'];

        return $config['responseMimeType'] === 'application/json'
            && $schema === (new StrictJsonSchemaAdapter)->apply(geminiSchema())
            && $schema['additionalProperties'] === false

            && $schema['required'] === ['recommendation', 'confidence', 'summary_ar', 'findings']
            && ! array_key_exists('maxLength', $schema['properties']['summary_ar'])
            && ! array_key_exists('responseSchema', $config);
    });
});

test('the tool path keeps the schema verbatim in parametersJsonSchema', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiFunctionCallBody())]);

    geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::Tool));

    Http::assertSent(function ($request): bool {
        $declaration = $request->data()['tools'][0]['functionDeclarations'][0];

        return $declaration['parametersJsonSchema'] === geminiSchema()
            && $declaration['parametersJsonSchema']['properties']['summary_ar']['maxLength'] === 600;
    });
});

test('a tool model declares the function with parametersJsonSchema and forces the call', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiFunctionCallBody())]);

    $response = geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::Tool));

    expect($response->payload)->toBe(geminiPayload());

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $declaration = $body['tools'][0]['functionDeclarations'][0];

        return $declaration['name'] === 'record_content_review'
            && $declaration['parametersJsonSchema'] === geminiSchema()
            && ! array_key_exists('parameters', $declaration)
            && $body['toolConfig']['functionCallingConfig'] === [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['record_content_review'],
            ]
            && ! array_key_exists('responseJsonSchema', $body['generationConfig']);
    });
});

test('a json object model carries the schema in the system prompt and tolerates a fenced answer', function (): void {
    $fenced = "```json\n".json_encode(geminiPayload(), JSON_UNESCAPED_UNICODE)."\n```";

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody([
        'candidates' => [['content' => ['parts' => [['text' => $fenced]]], 'finishReason' => 'STOP']],
    ]))]);

    $response = geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonObject));

    expect($response->payload)->toBe(geminiPayload());

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $prompt = $body['systemInstruction']['parts'][0]['text'];

        return $body['generationConfig']['responseMimeType'] === 'application/json'
            && ! array_key_exists('responseJsonSchema', $body['generationConfig'])
            && ! array_key_exists('tools', $body)
            && str_starts_with($prompt, 'POLICY')
            && str_contains($prompt, '"type":"object"');
    });
});

test('an answer split across several text parts is reassembled before decoding', function (): void {
    $json = json_encode(geminiPayload(), JSON_UNESCAPED_UNICODE);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody([
        'candidates' => [['content' => ['parts' => [
            ['text' => substr($json, 0, 10)],
            ['text' => substr($json, 10)],
        ]], 'finishReason' => 'STOP']],
    ]))]);

    expect(geminiProvider()->analyze(geminiRequest())->payload)->toBe(geminiPayload());
});

test('a generation 3 model is capped with thinkingLevel and never with the legacy budget', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest());

    Http::assertSent(fn ($request): bool => $request->data()['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'minimal']);
});

test('the thinking level is configurable and an unsupported value omits the config entirely', function (string $configured, ?array $expected): void {
    config()->set('services.gemini.thinking_level', $configured);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest());

    Http::assertSent(function ($request) use ($expected): bool {
        $config = $request->data()['generationConfig'];

        return $expected === null
            ? ! array_key_exists('thinkingConfig', $config)
            : $config['thinkingConfig'] === $expected;
    });
})->with([
    'minimal' => ['minimal', ['thinkingLevel' => 'minimal']],
    'low' => ['low', ['thinkingLevel' => 'low']],
    'medium' => ['medium', ['thinkingLevel' => 'medium']],
    'high' => ['high', ['thinkingLevel' => 'high']],
    'mixed case is normalised' => ['  HIGH ', ['thinkingLevel' => 'high']],
    'an unsupported value leaves the model to decide' => ['none', null],
    'an empty value leaves the model to decide' => ['', null],
]);

test('a generation 2.5 model keeps the numeric budget and never sends a thinking level', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema, [
        'model' => 'gemini-2.5-flash',
        'descriptor' => new ProviderModelDescriptor('gemini-2.5-flash', 300_000, 2_500_000, true, StructuredOutputStrategy::JsonSchema),
    ]));

    Http::assertSent(fn ($request): bool => $request->data()['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 0]);

    config()->set('services.gemini.thinking_budget', -1);

    geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema, [
        'model' => 'gemini-2.5-flash-lite',
        'descriptor' => new ProviderModelDescriptor('gemini-2.5-flash-lite', 100_000, 400_000, true, StructuredOutputStrategy::JsonSchema),
    ]));

    Http::assertSent(fn ($request): bool => ! array_key_exists('thinkingConfig', $request->data()['generationConfig']));
});

test('thinking tokens are counted as output and priced as output', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody([
        'usageMetadata' => geminiUsage(['thoughtsTokenCount' => 80]),
    ]))]);

    $response = geminiProvider()->analyze(geminiRequest());

    expect($response->outputTokens)->toBe(200)
        ->and($response->costMicros)->toBe(1_125);
});

test('usage that reports nothing leaves the tokens and the cost unknown', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody(['usageMetadata' => []]))]);

    $response = geminiProvider()->analyze(geminiRequest());

    expect($response->inputTokens)->toBeNull()
        ->and($response->outputTokens)->toBeNull()
        ->and($response->costMicros)->toBeNull();
});

test('images are sent as inline data and entries without bytes are skipped', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema, [
        'images' => [
            ['ref' => 'img-1', 'mime' => 'image/png', 'bytes' => 'PNGBYTES'],
            ['ref' => 'img-2', 'mime' => 'image/png', 'bytes' => null],
        ],
    ]));

    Http::assertSent(function ($request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        return count($parts) === 3
            && $parts[0] === ['text' => 'IMAGE img-1']
            && $parts[1] === ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNGBYTES')]]
            && array_key_exists('text', $parts[2]);
    });
});

test('a model without vision never receives an image part', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema, [
        'descriptor' => geminiDescriptor(StructuredOutputStrategy::JsonSchema, vision: false),
        'images' => [],
    ]));

    Http::assertSent(function ($request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        return count($parts) === 1 && array_key_exists('text', $parts[0]);
    });
});

test('the rate card prices the call because gemini reports no cost', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    expect(geminiProvider()->analyze(geminiRequest())->costMicros)->toBe(825);
});

test('a zero rated model is priced at zero rather than left unpriced', function (): void {
    config()->set('content_review.providers.gemini.models.gemini-test-free', [
        'input' => 0, 'output' => 0, 'vision' => true, 'structured' => 'json_schema',
    ]);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody())]);

    $response = geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema, [
        'model' => 'gemini-test-free',
        'descriptor' => new ProviderModelDescriptor('gemini-test-free', 0, 0, true, StructuredOutputStrategy::JsonSchema),
    ]));

    expect($response->costMicros)->toBe(0);
});

test('an uncatalogued model is left unpriced and falls back to tool calling', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiFunctionCallBody())]);

    $response = geminiProvider()->analyze(geminiRequest(StructuredOutputStrategy::JsonSchema, [
        'model' => 'gemini-not-a-model',
        'descriptor' => null,
    ]));

    expect($response->costMicros)->toBeNull()
        ->and($response->payload)->toBe(geminiPayload());
});

test('an answer cut off by the output ceiling is reported as truncation, not as garbage', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody([
        'candidates' => [['content' => ['parts' => []], 'finishReason' => 'MAX_TOKENS']],
        'usageMetadata' => ['promptTokenCount' => 1371, 'thoughtsTokenCount' => 2000],
    ]))]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::OutputTruncated
    );
});

test('an off contract answer that finished normally stays invalid structured output', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody([
        'candidates' => [['content' => ['parts' => [['text' => 'User Safety: safe']]], 'finishReason' => 'STOP']],
    ]))]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
});

test('a prompt blocked before any candidate is invalid structured output', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'promptFeedback' => ['blockReason' => 'SAFETY'],
        'usageMetadata' => ['promptTokenCount' => 500],
    ])]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
});

test('a call that spent tokens before failing still reports what it spent', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTextBody([
        'candidates' => [['content' => ['parts' => [['text' => '{"recommendation":']]], 'finishReason' => 'MAX_TOKENS']],
        'usageMetadata' => ['promptTokenCount' => 1371, 'candidatesTokenCount' => 0, 'thoughtsTokenCount' => 2000],
    ]))]);

    $exception = expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::OutputTruncated
    );

    $metrics = $exception->metrics();

    expect($metrics)->not->toBeNull()
        ->and($metrics->model)->toBe('gemini-3.6-flash')
        ->and($metrics->inputTokens)->toBe(1371)
        ->and($metrics->outputTokens)->toBe(2000)
        ->and($metrics->costMicros)->toBe(8_528)
        ->and($metrics->finishReason)->toBe('MAX_TOKENS');
});

test('a failure that never reached the provider carries no metrics', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    $exception = expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );

    expect($exception->metrics())->toBeNull();
});

test('a missing api key fails closed without contacting the provider', function (): void {
    config()->set('services.gemini.api_key', '');
    Http::fake();

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::ProviderAuthFailed
    );

    Http::assertNothingSent();
    expect(geminiProvider()->isConfigured())->toBeFalse();
});

test('an invalid argument is only invalid structured output when the schema is what was refused', function (string $message, ContentReviewErrorCode $expected): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiErrorBody('INVALID_ARGUMENT', $message), 400)]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        $expected
    );
})->with([
    'the response schema was rejected' => [
        'Invalid JSON payload received. Unknown name "maxLength" at \'generation_config.response_json_schema\'.',
        ContentReviewErrorCode::InvalidStructuredOutput,
    ],
    'the function declaration was rejected' => [
        'Invalid value at \'tools[0].function_declarations[0].parameters_json_schema\'.',
        ContentReviewErrorCode::InvalidStructuredOutput,
    ],
    'an unrelated parameter was rejected' => [
        'Unable to submit request because thinking is not supported by this model. Remove thinkingBudget and try again.',
        ContentReviewErrorCode::ProviderUnavailable,
    ],
    'the image was rejected' => [
        'Provided image is not valid.',
        ContentReviewErrorCode::ProviderUnavailable,
    ],
]);

test('the error status decides the code before the http code does', function (string $status, int $code, ContentReviewErrorCode $expected): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiErrorBody($status, 'nope', $code), $code)]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        $expected
    );
})->with([
    'free tier unavailable for the account' => ['FAILED_PRECONDITION', 400, ContentReviewErrorCode::ProviderAuthFailed],
    'unauthenticated' => ['UNAUTHENTICATED', 401, ContentReviewErrorCode::ProviderAuthFailed],
    'permission denied' => ['PERMISSION_DENIED', 403, ContentReviewErrorCode::ProviderAuthFailed],
    'unknown model' => ['NOT_FOUND', 404, ContentReviewErrorCode::ProviderUnavailable],
    'quota exhausted' => ['RESOURCE_EXHAUSTED', 429, ContentReviewErrorCode::ProviderRateLimited],
    'deadline exceeded' => ['DEADLINE_EXCEEDED', 504, ContentReviewErrorCode::ProviderTimeout],
    'internal' => ['INTERNAL', 500, ContentReviewErrorCode::ProviderUnavailable],
    'unavailable' => ['UNAVAILABLE', 503, ContentReviewErrorCode::ProviderUnavailable],
]);

test('http failures without an error status still map onto stable error codes', function (int $status, ContentReviewErrorCode $expected): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('gateway page', $status)]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        $expected
    );
})->with([
    'unauthorized' => [401, ContentReviewErrorCode::ProviderAuthFailed],
    'forbidden' => [403, ContentReviewErrorCode::ProviderAuthFailed],
    'bad request' => [400, ContentReviewErrorCode::ProviderUnavailable],
    'timeout' => [408, ContentReviewErrorCode::ProviderTimeout],
    'gateway timeout' => [504, ContentReviewErrorCode::ProviderTimeout],
    'rate limited' => [429, ContentReviewErrorCode::ProviderRateLimited],
    'server error' => [500, ContentReviewErrorCode::ProviderUnavailable],
]);

test('an upstream error returned with a 200 is still a failure', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiErrorBody('INTERNAL', 'upstream down', 500), 200)]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::ProviderUnavailable
    );
});

test('a connection failure is reported as a timeout', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );
});

test('an answer that is not the agreed structure is rejected', function (array $body, StructuredOutputStrategy $strategy): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body)]);

    expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest($strategy)),
        ContentReviewErrorCode::InvalidStructuredOutput
    );
})->with([
    'no candidates' => [['usageMetadata' => []], StructuredOutputStrategy::JsonSchema],
    'no parts' => [['candidates' => [['content' => ['parts' => []]]]], StructuredOutputStrategy::JsonSchema],
    'text is not json' => [['candidates' => [['content' => ['parts' => [['text' => 'here you go']]]]]], StructuredOutputStrategy::JsonSchema],
    'no function call' => [['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]], StructuredOutputStrategy::Tool],
    'a different function' => [['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'other', 'args' => []]]]]]]], StructuredOutputStrategy::Tool],
    'arguments are not an object' => [['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'record_content_review', 'args' => 'nope']]]]]]], StructuredOutputStrategy::Tool],
]);

test('the api key never reaches an exception message, its string form, or the logs', function (): void {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('failed to connect with x-goog-api-key: '.GEMINI_TEST_API_KEY));

    $exception = expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::ProviderTimeout
    );

    expect($exception->getMessage())->not->toContain(GEMINI_TEST_API_KEY)
        ->and((string) $exception)->not->toContain(GEMINI_TEST_API_KEY);

    Log::shouldNotHaveReceived('error');
});

test('a provider error message is never echoed back to the caller', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiErrorBody('INVALID_ARGUMENT', 'API key '.GEMINI_TEST_API_KEY.' is invalid for response_json_schema'),
        400
    )]);

    $exception = expectGeminiErrorCode(
        fn () => geminiProvider()->analyze(geminiRequest()),
        ContentReviewErrorCode::InvalidStructuredOutput
    );

    expect($exception->getMessage())->not->toContain(GEMINI_TEST_API_KEY)
        ->and((new ErrorMessageRedactor)->redactString('x-goog-api-key: '.GEMINI_TEST_API_KEY))
        ->not->toContain(GEMINI_TEST_API_KEY);
});

<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use App\Services\ContentReview\Support\StrictJsonSchemaAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiContentReviewProvider implements ContentReviewProvider
{
    private const TOOL_NAME = 'record_content_review';

    /** Generation from which thinkingLevel replaces the legacy numeric thinkingBudget. */
    private const THINKING_LEVEL_MIN_GENERATION = 3;

    private const THINKING_LEVELS = ['minimal', 'low', 'medium', 'high'];

    /**
     * Fragments that mark an INVALID_ARGUMENT as a rejection of the result schema or of the
     * requested response format, rather than of some unrelated request parameter.
     */
    private const SCHEMA_ERROR_MARKERS = [
        'response_json_schema',
        'responsejsonschema',
        'parameters_json_schema',
        'parametersjsonschema',
        'response_schema',
        'responseschema',
        'response_mime_type',
        'responsemimetype',
        'function_declarations',
        'functiondeclarations',
        'schema',
    ];

    public function __construct(
        private readonly ProviderCostCalculator $costs,
        private readonly StrictJsonSchemaAdapter $strictSchema,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function analyze(ProviderReviewRequest $request): ProviderReviewResponse
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderAuthFailed);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'content-type' => 'application/json',
            ])
                ->timeout($request->timeoutSeconds)
                ->post($this->endpoint($request), $this->body($request));
        } catch (ConnectionException) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderTimeout);
        } catch (Throwable) {
            throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
        }

        $this->assertSuccessful($response);

        $metrics = $this->metrics($response, $request, (int) round((microtime(true) - $startedAt) * 1000));

        return new ProviderReviewResponse(
            $this->extractPayload($request, $response, $metrics),
            $metrics->model,
            $metrics->inputTokens,
            $metrics->outputTokens,
            $metrics->costMicros,
            $metrics->latencyMs,
            $metrics->providerRequestId,
        );
    }

    /**
     * The reported model is the requested one: Gemini echoes a resolved modelVersion that can be
     * a dated or preview build the catalog does not price, which would drop the cost silently.
     */
    private function metrics(Response $response, ProviderReviewRequest $request, int $latencyMs): ProviderCallMetrics
    {
        $model = $request->model;
        $inputTokens = $this->intOrNull($response->json('usageMetadata.promptTokenCount'));
        $outputTokens = $this->outputTokens($response);

        return new ProviderCallMetrics(
            $model,
            $inputTokens,
            $outputTokens,
            $this->costs->costMicros($this->name(), $model, $inputTokens, $outputTokens),
            $latencyMs,
            $this->requestId($response),
            $this->stringOrNull($response->json('candidates.0.finishReason')),
        );
    }

    /**
     * Thinking tokens are billed as output, so they belong in the output total.
     */
    private function outputTokens(Response $response): ?int
    {
        $answer = $this->intOrNull($response->json('usageMetadata.candidatesTokenCount'));
        $thoughts = $this->intOrNull($response->json('usageMetadata.thoughtsTokenCount'));

        if ($answer === null && $thoughts === null) {
            return null;
        }

        return ($answer ?? 0) + ($thoughts ?? 0);
    }

    private function endpoint(ProviderReviewRequest $request): string
    {
        return rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/')
            .'/models/'.$request->model.':generateContent';
    }

    private function body(ProviderReviewRequest $request): array
    {
        $strategy = $request->structuredOutputStrategy();

        return array_replace([
            'systemInstruction' => ['parts' => [['text' => $this->systemPrompt($request, $strategy)]]],
            'contents' => [['role' => 'user', 'parts' => $this->buildParts($request)]],
            'generationConfig' => $this->generationConfig($request, $strategy),
        ], $this->toolParameters($request, $strategy));
    }

    private function systemPrompt(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): string
    {
        if ($strategy !== StructuredOutputStrategy::JsonObject) {
            return $request->policyInstructions;
        }

        return $request->policyInstructions
            ."\n\nReturn a single JSON object that validates against this schema, and nothing else:\n"
            .(string) json_encode($request->resultSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * responseJsonSchema takes JSON Schema as written, unlike the legacy responseSchema field which
     * is an OpenAPI proto message that rejects unknown keys. It still validates strictly: a schema
     * that closes itself with additionalProperties:false while leaving properties out of `required`
     * is refused with INVALID_ARGUMENT, which is what StrictJsonSchemaAdapter already normalises for
     * the other strict-schema provider.
     */
    private function generationConfig(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): array
    {
        $config = array_replace(
            ['maxOutputTokens' => $request->maxOutputTokens],
            $this->thinkingConfig($request->model)
        );

        return array_replace($config, match ($strategy) {
            StructuredOutputStrategy::Tool => [],
            StructuredOutputStrategy::JsonSchema => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $this->strictSchema->apply($request->resultSchema),
            ],
            StructuredOutputStrategy::JsonObject => [
                'responseMimeType' => 'application/json',
            ],
        });
    }

    /**
     * How thinking is capped differs by model generation, and the two knobs are mutually
     * exclusive: sending both returns a 400. Generation 3 and later take a thinkingLevel; the
     * older models keep the numeric thinkingBudget they were verified against.
     */
    private function thinkingConfig(string $model): array
    {
        if ($this->usesThinkingLevel($model)) {
            $level = strtolower(trim((string) config('services.gemini.thinking_level', 'minimal')));

            return in_array($level, self::THINKING_LEVELS, true)
                ? ['thinkingConfig' => ['thinkingLevel' => $level]]
                : [];
        }

        $budget = (int) config('services.gemini.thinking_budget', 0);

        return $budget >= 0 ? ['thinkingConfig' => ['thinkingBudget' => $budget]] : [];
    }

    private function usesThinkingLevel(string $model): bool
    {
        return preg_match('/^gemini-(\d+)/', $model, $matches) === 1
            && (int) $matches[1] >= self::THINKING_LEVEL_MIN_GENERATION;
    }

    private function toolParameters(ProviderReviewRequest $request, StructuredOutputStrategy $strategy): array
    {
        if ($strategy !== StructuredOutputStrategy::Tool) {
            return [];
        }

        return [
            'tools' => [[
                'functionDeclarations' => [[
                    'name' => self::TOOL_NAME,
                    'description' => 'Record the structured content review result.',
                    'parametersJsonSchema' => $request->resultSchema,
                ]],
            ]],
            'toolConfig' => [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => [self::TOOL_NAME],
                ],
            ],
        ];
    }

    private function buildParts(ProviderReviewRequest $request): array
    {
        $parts = [];

        foreach ($request->images as $image) {
            if (($image['bytes'] ?? null) === null) {
                continue;
            }

            $ref = (string) ($image['ref'] ?? '');

            if ($ref !== '') {
                $parts[] = ['text' => 'IMAGE '.$ref];
            }

            $parts[] = [
                'inlineData' => [
                    'mimeType' => (string) $image['mime'],
                    'data' => base64_encode((string) $image['bytes']),
                ],
            ];
        }

        $parts[] = ['text' => $this->buildTextBlocks($request)];

        return $parts;
    }

    private function buildTextBlocks(ProviderReviewRequest $request): string
    {
        $parts = [];

        foreach ($request->textBlocks as $block) {
            $field = (string) ($block['field'] ?? 'other');
            $locale = (string) ($block['locale'] ?? 'ar');
            $value = (string) ($block['value'] ?? '');

            $parts[] = "<<<FIELD:{$field}:{$locale}>>>\n{$value}\n<<<END>>>";
        }

        $facts = [];

        foreach ($request->structuredFacts as $key => $value) {
            $facts[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return implode("\n\n", array_merge($parts, ['<<<FIELD:facts>>>', implode("\n", $facts), '<<<END>>>']));
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            if (is_array($response->json('error'))) {
                throw ContentReviewProviderException::of(ContentReviewErrorCode::ProviderUnavailable);
            }

            return;
        }

        throw ContentReviewProviderException::of($this->classify($response));
    }

    /**
     * Gemini folds unrelated conditions into HTTP 400, so error.status decides first and the HTTP
     * code is only the fallback. Only a schema or response-format rejection is invalid output.
     */
    private function classify(Response $response): ContentReviewErrorCode
    {
        $status = strtoupper($this->stringOrNull($response->json('error.status')) ?? '');

        return match ($status) {
            'INVALID_ARGUMENT' => $this->mentionsSchema($this->stringOrNull($response->json('error.message')) ?? '')
                ? ContentReviewErrorCode::InvalidStructuredOutput
                : ContentReviewErrorCode::ProviderUnavailable,
            'FAILED_PRECONDITION', 'UNAUTHENTICATED', 'PERMISSION_DENIED' => ContentReviewErrorCode::ProviderAuthFailed,
            'RESOURCE_EXHAUSTED' => ContentReviewErrorCode::ProviderRateLimited,
            'DEADLINE_EXCEEDED' => ContentReviewErrorCode::ProviderTimeout,
            'NOT_FOUND', 'UNAVAILABLE', 'INTERNAL' => ContentReviewErrorCode::ProviderUnavailable,
            default => $this->classifyByStatusCode($response->status()),
        };
    }

    private function classifyByStatusCode(int $status): ContentReviewErrorCode
    {
        return match (true) {
            in_array($status, [401, 402, 403], true) => ContentReviewErrorCode::ProviderAuthFailed,
            in_array($status, [408, 504], true) => ContentReviewErrorCode::ProviderTimeout,
            $status === 429 => ContentReviewErrorCode::ProviderRateLimited,
            default => ContentReviewErrorCode::ProviderUnavailable,
        };
    }

    /**
     * Used for classification only. The message itself is never returned, stored or logged.
     */
    private function mentionsSchema(string $message): bool
    {
        $message = strtolower($message);

        foreach (self::SCHEMA_ERROR_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function extractPayload(ProviderReviewRequest $request, Response $response, ProviderCallMetrics $metrics): array
    {
        if (! is_array($response->json())) {
            throw ContentReviewProviderException::afterCall(ContentReviewErrorCode::InvalidStructuredOutput, $metrics);
        }

        $decoded = $request->structuredOutputStrategy() === StructuredOutputStrategy::Tool
            ? $this->functionCallArguments($response)
            : $this->decodeText($this->candidateText($response));

        if (is_array($decoded)) {
            return $decoded;
        }

        throw ContentReviewProviderException::afterCall(
            $metrics->finishReason === 'MAX_TOKENS'
                ? ContentReviewErrorCode::OutputTruncated
                : ContentReviewErrorCode::InvalidStructuredOutput,
            $metrics
        );
    }

    private function functionCallArguments(Response $response): ?array
    {
        foreach ($this->candidateParts($response) as $part) {
            $call = is_array($part) ? ($part['functionCall'] ?? null) : null;

            if (is_array($call) && ($call['name'] ?? null) === self::TOOL_NAME && is_array($call['args'] ?? null)) {
                return $call['args'];
            }
        }

        return null;
    }

    private function candidateText(Response $response): ?string
    {
        $text = '';

        foreach ($this->candidateParts($response) as $part) {
            $chunk = is_array($part) ? ($part['text'] ?? null) : null;

            if (is_string($chunk)) {
                $text .= $chunk;
            }
        }

        return $this->stringOrNull($text);
    }

    private function candidateParts(Response $response): array
    {
        $parts = $response->json('candidates.0.content.parts');

        return is_array($parts) ? $parts : [];
    }

    private function decodeText(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($this->stripCodeFence($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function stripCodeFence(string $raw): string
    {
        $trimmed = trim($raw);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = (string) preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $trimmed);

        return trim((string) preg_replace('/```$/', '', trim($trimmed)));
    }

    private function requestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('x-goog-request-id'))
            ?? $this->stringOrNull($response->header('x-request-id'));
    }

    private function apiKey(): string
    {
        return trim((string) config('services.gemini.api_key'));
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Providers;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\Services\ContentReview\Support\ProviderCostCalculator;
use App\Services\ContentReview\Support\StrictJsonSchemaAdapter;
use Illuminate\Http\Client\Response;

final class GeminiContentReviewProvider extends HttpContentReviewProvider
{
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

    protected function configKey(): string
    {
        return 'gemini';
    }

    protected function headers(string $apiKey): array
    {
        return [
            'x-goog-api-key' => $apiKey,
            'content-type' => 'application/json',
        ];
    }

    protected function endpoint(ProviderReviewRequest $request): string
    {
        return $this->baseUrl('https://generativelanguage.googleapis.com/v1beta')
            .'/models/'.$request->model.':generateContent';
    }

    protected function body(ProviderReviewRequest $request): array
    {
        $strategy = $request->structuredOutputStrategy();

        return array_replace([
            'systemInstruction' => ['parts' => [['text' => $this->systemPrompt($request, $strategy)]]],
            'contents' => [['role' => 'user', 'parts' => $this->contentParts($request)]],
            'generationConfig' => $this->generationConfig($request, $strategy),
        ], $this->toolParameters($request, $strategy));
    }

    protected function truncationFinishReason(): string
    {
        return 'MAX_TOKENS';
    }

    protected function textPart(string $text): array
    {
        return ['text' => $text];
    }

    protected function imagePart(string $mime, string $bytes): array
    {
        return ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]];
    }

    /**
     * The reported model is the requested one: Gemini echoes a resolved modelVersion that can be
     * a dated or preview build the catalog does not price, which would drop the cost silently.
     */
    protected function metrics(Response $response, ProviderReviewRequest $request, int $latencyMs): ProviderCallMetrics
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

    protected function extractPayload(ProviderReviewRequest $request, Response $response, ProviderCallMetrics $metrics): array
    {
        if (is_array($response->json())) {
            $decoded = $request->structuredOutputStrategy() === StructuredOutputStrategy::Tool
                ? $this->functionCallArguments($response)
                : $this->decodeText($this->candidateText($response));

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->failUnusableOutput($metrics);
    }

    protected function requestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('x-goog-request-id'))
            ?? $this->stringOrNull($response->header('x-request-id'));
    }

    protected function assertSuccessful(Response $response): void
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
                    'description' => self::TOOL_DESCRIPTION,
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
}

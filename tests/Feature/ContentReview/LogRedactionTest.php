<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\ContentReview\Support\ContentReviewLogContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

final class LogRedactionTest extends TestCase
{
    use BuildsContentReviewFixtures;

    private const CANARIES = [
        'sk-ant-leak-canary',
        'api_key',
        'raw_response',
        'chain_of_thought',
        'policy_instructions',
        'auction-media/',
    ];

    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        config()->set('services.anthropic.api_key', 'sk-ant-leak-canary');
        Http::preventStrayRequests();
        Queue::fake();
        app(FakeContentReviewProvider::class)->reset();
        app(ContentReviewCircuitBreaker::class)->reset();

        $this->records = [];
        Log::listen(function (MessageLogged $event): void {
            $this->records[] = $event;
        });
    }

    public function test_a_completed_review_logs_only_allowlisted_keys(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($auction);

        $completed = $this->recordsFor('content_review.completed');

        $this->assertCount(1, $completed);
        $this->assertAllowlisted($completed[0]->context);
        $this->assertSame('shadow', $completed[0]->context['mode']);
        $this->assertSame('completed', $completed[0]->context['status']);
    }

    public function test_a_failed_review_logs_a_warning_without_the_provider_message(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $this->fakeProvider()->failWith(ContentReviewErrorCode::ProviderAuthFailed);
        $this->processActiveReview($auction);

        $failures = $this->recordsFor('content_review.failed');

        $this->assertNotEmpty($failures);

        foreach ($failures as $record) {
            $this->assertSame('warning', $record->level);
            $this->assertAllowlisted($record->context);
        }
    }

    public function test_no_log_record_carries_the_content_the_prompt_or_the_key(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $this->processActiveReview($auction);

        $encoded = json_encode(array_map(
            static fn (MessageLogged $event): array => ['message' => $event->message, 'context' => $event->context],
            $this->records
        ), JSON_THROW_ON_ERROR);

        foreach (self::CANARIES as $canary) {
            $this->assertStringNotContainsString($canary, $encoded);
        }

        $this->assertStringNotContainsString((string) $auction->title, $encoded);
        $this->assertStringNotContainsString((string) $auction->description, $encoded);
        $this->assertStringNotContainsString((string) $this->activeReview($auction)->content_hash, $encoded);
    }

    public function test_the_builder_drops_every_key_outside_its_allowlist(): void
    {
        $auction = $this->reviewedAuction(ReviewMode::Shadow);
        $this->fakeProvider()->respondWith($this->cleanResultPayload());
        $review = $this->processActiveReview($auction);

        $context = app(ContentReviewLogContext::class)->forReview($review, [
            'api_key' => 'sk-ant-leak-canary',
            'prompt' => 'you are a reviewer',
            'raw_response' => ['chain_of_thought' => 'secret'],
            'content_hash' => (string) $review->content_hash,
            'stage' => 'analysis',
        ]);

        $this->assertAllowlisted($context);
        $this->assertArrayNotHasKey('api_key', $context);
        $this->assertArrayNotHasKey('prompt', $context);
        $this->assertArrayNotHasKey('raw_response', $context);
        $this->assertArrayNotHasKey('content_hash', $context);
        $this->assertSame('analysis', $context['stage']);
    }

    public function test_the_builder_never_emits_a_non_scalar_value(): void
    {
        $context = app(ContentReviewLogContext::class)->operational([
            'observed' => ['nested' => 'array'],
            'threshold' => 10,
        ]);

        $this->assertSame(['threshold' => 10], $context);
    }

    private function assertAllowlisted(array $context): void
    {
        $unexpected = array_diff(array_keys($context), ContentReviewLogContext::allowedKeys());

        $this->assertSame([], array_values($unexpected));
    }

    private function recordsFor(string $message): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (MessageLogged $event): bool => $event->message === $message
        ));
    }
}

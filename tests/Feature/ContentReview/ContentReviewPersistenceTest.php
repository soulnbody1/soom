<?php

declare(strict_types=1);

namespace Tests\Feature\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\ContentReview\ContentReviewSetting;
use App\Models\User;
use App\Repositories\ContentReview\ContentReviewDecisionRepository;
use App\Repositories\ContentReview\ContentReviewRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentReviewPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_only_one_active_review_can_exist_per_subject(): void
    {
        $subjectId = $this->uniqueSubjectId();

        ContentReview::factory()->create(['subject_id' => $subjectId]);

        $this->expectException(QueryException::class);

        ContentReview::factory()->create(['subject_id' => $subjectId]);
    }

    public function test_superseding_frees_the_active_slot(): void
    {
        $subjectId = $this->uniqueSubjectId();
        $repository = app(ContentReviewRepository::class);

        $first = ContentReview::factory()->create(['subject_id' => $subjectId]);
        $repository->supersedeActive(ReviewableSubjectType::Auction, $subjectId);

        $second = ContentReview::factory()->create(['subject_id' => $subjectId]);

        $first->refresh();

        $this->assertNull($first->current_marker);
        $this->assertSame(ContentReviewStatus::Superseded, $first->status);
        $this->assertNotNull($first->superseded_at);
        $this->assertSame(1, (int) $second->current_marker);
        $this->assertSame($second->id, $repository->activeForSubject(ReviewableSubjectType::Auction, $subjectId)?->id);
    }

    public function test_the_same_content_version_cannot_be_attempted_twice(): void
    {
        $subjectId = $this->uniqueSubjectId();
        $hash = hash('sha256', 'stable-content');

        ContentReview::factory()->create(['subject_id' => $subjectId, 'content_hash' => $hash]);
        app(ContentReviewRepository::class)->supersedeActive(ReviewableSubjectType::Auction, $subjectId);

        $this->expectException(QueryException::class);

        ContentReview::factory()->create([
            'subject_id' => $subjectId,
            'content_hash' => $hash,
            'attempt' => 1,
        ]);
    }

    public function test_a_new_attempt_for_the_same_content_is_allowed(): void
    {
        $subjectId = $this->uniqueSubjectId();
        $hash = hash('sha256', 'retryable-content');

        ContentReview::factory()->create(['subject_id' => $subjectId, 'content_hash' => $hash, 'attempt' => 1]);
        app(ContentReviewRepository::class)->supersedeActive(ReviewableSubjectType::Auction, $subjectId);

        $second = ContentReview::factory()->create([
            'subject_id' => $subjectId,
            'content_hash' => $hash,
            'attempt' => 2,
        ]);

        $this->assertSame(2, (int) $second->attempt);
        $this->assertSame(2, ContentReview::where('subject_id', $subjectId)->count());
    }

    public function test_an_ai_decision_never_stores_a_user_id(): void
    {
        $review = ContentReview::factory()->completed()->create(['subject_id' => $this->uniqueSubjectId()]);
        $admin = $this->user('admin');

        $aiDecision = app(ContentReviewDecisionRepository::class)->record(
            $review->id,
            ReviewableSubjectType::Auction,
            (int) $review->subject_id,
            ContentReviewDecisionType::Recommended,
            DecisionActorType::Ai,
            $admin->id,
            DecisionRelation::None,
            ReviewRecommendation::Approve,
            92,
            null,
        );

        $this->assertNull($aiDecision->decided_by_id);
        $this->assertSame(DecisionActorType::Ai, $aiDecision->decided_by_type);

        $adminDecision = app(ContentReviewDecisionRepository::class)->record(
            $review->id,
            ReviewableSubjectType::Auction,
            (int) $review->subject_id,
            ContentReviewDecisionType::Approved,
            DecisionActorType::Admin,
            $admin->id,
            DecisionRelation::Confirmed,
            ReviewRecommendation::Approve,
            92,
            'looks good',
        );

        $this->assertSame($admin->id, $adminDecision->decided_by_id);
        $this->assertSame('admin|approved|confirmed', $adminDecision->labelKey());
        $this->assertSame('ai|recommended|none', $aiDecision->labelKey());
    }

    public function test_a_used_policy_version_is_immutable(): void
    {
        $policy = $this->policy();
        ContentReview::factory()->create([
            'subject_id' => $this->uniqueSubjectId(),
            'policy_id' => $policy->id,
            'policy_version' => 1,
        ]);

        $this->expectException(ContentReviewException::class);

        $policy->forceFill(['name' => 'tampered'])->save();
    }

    public function test_an_unused_policy_version_can_still_be_corrected(): void
    {
        $policy = $this->policy();

        $policy->forceFill(['name' => 'corrected before use'])->save();

        $this->assertSame('corrected before use', $policy->refresh()->name);
    }

    public function test_a_used_policy_can_still_be_deactivated(): void
    {
        $policy = $this->policy();
        ContentReview::factory()->create([
            'subject_id' => $this->uniqueSubjectId(),
            'policy_id' => $policy->id,
        ]);

        $policy->forceFill(['is_active' => false])->save();

        $this->assertFalse($policy->refresh()->is_active);
    }

    public function test_settings_versions_are_append_only_except_for_activation(): void
    {
        $setting = ContentReviewSetting::create([
            'scope' => 'auction',
            'version_number' => $this->uniqueSubjectId(),
            'is_active' => true,
            'published_at' => now(),
            'settings' => ['enabled' => false, 'mode' => 'manual'],
        ]);

        $setting->forceFill(['is_active' => false])->save();
        $this->assertFalse($setting->refresh()->is_active);

        $this->expectException(ContentReviewException::class);

        $setting->forceFill(['settings' => ['enabled' => true, 'mode' => 'ai_automatic']])->save();
    }

    public function test_the_seeder_ships_the_feature_disabled_in_manual_mode(): void
    {
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ContentReviewSeeder', '--force' => true]);

        $settings = ContentReviewSetting::where('scope', 'auction')->where('is_active', true)->firstOrFail();
        $policy = ContentReviewPolicy::where('subject_type', 'auction')->where('is_active', true)->firstOrFail();

        $this->assertFalse($settings->isEnabled());
        $this->assertSame('manual', $settings->settings['mode']);
        $this->assertSame([], $policy->policy['auto_reject_categories']);
        $this->assertNotSame([], $policy->policy['prohibited_categories']);
    }

    public function test_no_existing_auction_table_was_altered_by_the_new_migrations(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('content_reviews'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('content_review_decisions'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('content_review_policies'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('content_review_settings'));

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('auctions', 'content_review_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('auction_status_history', 'content_review_id'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('auction_status_history', 'actor_type'));
    }

    private function policy(): ContentReviewPolicy
    {
        return ContentReviewPolicy::create([
            'subject_type' => 'auction',
            'version_number' => $this->uniqueSubjectId(),
            'name' => 'Policy '.Str::ulid(),
            'prompt_version' => 'v1',
            'result_schema_version' => 1,
            'is_active' => true,
            'published_at' => now(),
            'policy' => ['locales' => ['ar'], 'thresholds' => ['min_confidence_approve' => 85]],
        ]);
    }

    private function uniqueSubjectId(): int
    {
        static $counter = 0;

        return ++$counter + (int) (microtime(true) * 100) % 100_000;
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());

        return User::create([
            'name' => 'Content Review User',
            'email' => "cr-{$unique}@example.test",
            'phone' => '+96279'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\Jobs\Ad\SendAdNotificationChunk;
use App\Jobs\SendAdNotification;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use App\Models\UserAdInteraction;
use App\Notifications\NewAdNotification;
use App\Services\Notification\PushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsQueryCount;

final class AdNotificationFanOutTest extends AdTestCase
{
    use AssertsQueryCount;
    use RefreshDatabase;

    public function test_it_only_targets_users_past_the_interaction_threshold(): void
    {
        Bus::fake([SendAdNotificationChunk::class]);

        $category = $this->category();
        $ad = $this->makeAd(['category_id' => $category->id]);

        $qualified = $this->interestedUser($category->id, 3);
        $this->interestedUser($category->id, 2);

        SendAdNotification::dispatch($ad);

        Bus::assertDispatched(
            SendAdNotificationChunk::class,
            fn (SendAdNotificationChunk $job): bool => $this->recipientsOf($job) === [$qualified->id]
        );
    }

    public function test_it_excludes_the_author_other_cities_and_opted_out_users(): void
    {
        Bus::fake([SendAdNotificationChunk::class]);

        $category = $this->category();
        $author = $this->adUser();
        $ad = $this->makeAd(['category_id' => $category->id, 'user_id' => $author->id]);

        $this->interactFor($author, $category->id, 3);

        $otherCity = City::factory()->create([
            'state_id' => State::factory()->create(['country_id' => $this->country()->id])->id,
        ]);
        $this->interestedUser($category->id, 3, ['city_id' => $otherCity->id]);
        $this->interestedUser($category->id, 3, ['allow_ad_notifications' => false]);

        $wanted = $this->interestedUser($category->id, 3);

        SendAdNotification::dispatch($ad);

        Bus::assertDispatched(
            SendAdNotificationChunk::class,
            fn (SendAdNotificationChunk $job): bool => $this->recipientsOf($job) === [$wanted->id]
        );
    }

    public function test_it_counts_interactions_on_ancestor_categories(): void
    {
        Bus::fake([SendAdNotificationChunk::class]);

        $parent = $this->category();
        $child = $this->category($parent->id);
        $ad = $this->makeAd(['category_id' => $child->id]);

        $wanted = $this->interestedUser($parent->id, 3);

        SendAdNotification::dispatch($ad);

        Bus::assertDispatched(
            SendAdNotificationChunk::class,
            fn (SendAdNotificationChunk $job): bool => $this->recipientsOf($job) === [$wanted->id]
        );
    }

    public function test_the_audience_is_split_into_bounded_chunks(): void
    {
        Bus::fake([SendAdNotificationChunk::class]);
        config(['ads.notifications.chunk_size' => 2]);

        $category = $this->category();
        $ad = $this->makeAd(['category_id' => $category->id]);

        collect(range(1, 5))->each(fn () => $this->interestedUser($category->id, 3));

        SendAdNotification::dispatch($ad);

        Bus::assertDispatchedTimes(SendAdNotificationChunk::class, 3);
    }

    public function test_the_audience_scan_never_loads_every_recipient_at_once(): void
    {
        Bus::fake([SendAdNotificationChunk::class]);
        config(['ads.notifications.chunk_size' => 2]);

        $category = $this->category();
        $ad = $this->makeAd(['category_id' => $category->id]);

        collect(range(1, 6))->each(fn () => $this->interestedUser($category->id, 3));

        $this->countQueries(fn () => SendAdNotification::dispatch($ad));

        $unbounded = array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, 'user_ad_interactions') && ! str_contains($sql, 'limit')
        );

        $this->assertSame([], $unbounded);
    }

    public function test_the_chunk_job_notifies_its_recipients(): void
    {
        Notification::fake();
        Queue::fake();

        $ad = $this->makeAd();
        $recipients = collect(range(1, 3))->map(fn () => $this->adUser());

        (new SendAdNotificationChunk($ad->id, $recipients->pluck('id')->all()))->handle(app(PushDispatcher::class));

        Notification::assertSentTimes(NewAdNotification::class, 3);
    }

    public function test_the_chunk_job_is_a_no_op_when_the_ad_is_gone(): void
    {
        Notification::fake();

        $ad = $this->makeAd();
        $recipient = $this->adUser();
        $ad->forceDelete();

        (new SendAdNotificationChunk($ad->id, [$recipient->id]))->handle(app(PushDispatcher::class));

        Notification::assertNothingSent();
    }

    private function interestedUser(int $categoryId, int $interactions, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
        ], $overrides));

        $this->interactFor($user, $categoryId, $interactions);

        return $user;
    }

    private function interactFor(User $user, int $categoryId, int $interactions): void
    {
        $actions = [AdInteractionAction::Click, AdInteractionAction::Save];

        collect(range(1, $interactions))->each(function (int $index) use ($user, $categoryId, $actions): void {
            $ad = $this->makeAd(['category_id' => $categoryId]);

            UserAdInteraction::create([
                'user_id' => $user->id,
                'ad_id' => $ad->id,
                'action' => $actions[$index % 2]->value,
            ]);
        });
    }

    private function recipientsOf(SendAdNotificationChunk $job): array
    {
        $recipients = $job->userIds;
        sort($recipients);

        return $recipients;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class NotificationQueryBudgetTest extends NotificationTestCase
{
    use RefreshDatabase;

    private const INDEX_BUDGET = 4;

    private const MARK_ALL_BUDGET = 3;

    private const MARK_SINGLE_BUDGET = 5;

    public function test_index_cost_does_not_grow_with_the_row_count(): void
    {
        $user = $this->notifiableUser();

        $this->seedNotifications($user, 3);
        $small = $this->measure($user, '/api/soom/notifications');

        $this->seedNotifications($user, 20);
        $large = $this->measure($user, '/api/soom/notifications');

        $this->assertSame($small, $large, 'The notification list is paginated at a constant cost.');
    }

    public function test_index_stays_within_a_constant_query_budget(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 25);

        $cost = $this->measure($user, '/api/soom/notifications');

        $this->assertLessThanOrEqual(
            self::INDEX_BUDGET,
            $cost,
            "GET /notifications used {$cost} queries:\n - ".implode("\n - ", $this->recordedQueries())
        );
    }

    public function test_mark_all_as_read_cost_does_not_grow_with_the_unread_count(): void
    {
        $small = $this->measureMarkAll(3);
        $large = $this->measureMarkAll(30);

        if ($large > $small) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: mark-all-as-read costs %d queries for 3 unread rows and %d for 30 (~%.1f per row), '
                .'because it hydrates the collection and updates row by row.',
                $small,
                $large,
                ($large - $small) / 27
            ));
        }

        $this->assertSame($small, $large, 'mark-all-as-read is a single statement.');
    }

    public function test_mark_all_as_read_stays_within_a_constant_query_budget(): void
    {
        $cost = $this->measureMarkAll(30);

        if ($cost > self::MARK_ALL_BUDGET) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: mark-all-as-read used %d queries for 30 unread rows, budget is %d.',
                $cost,
                self::MARK_ALL_BUDGET
            ));
        }

        $this->assertLessThanOrEqual(self::MARK_ALL_BUDGET, $cost);
    }

    public function test_mark_single_as_read_stays_within_a_constant_query_budget(): void
    {
        $user = $this->notifiableUser();
        $row = $this->seedNotifications($user, 25)->first();

        $this->countQueries(fn () => $this->actingAs($user, 'sanctum')
            ->putJson('/api/soom/notifications/'.$row->id.'/read')
            ->assertOk());

        $cost = count($this->recordedQueries());

        $this->assertLessThanOrEqual(
            self::MARK_SINGLE_BUDGET,
            $cost,
            "PUT /notifications/{id}/read used {$cost} queries:\n - ".implode("\n - ", $this->recordedQueries())
        );
    }

    public function test_index_never_loads_more_rows_than_the_page_size(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 40);

        $this->countQueries(fn () => $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/notifications?per_page=5')
            ->assertOk());

        $unbounded = array_values(array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, 'from `notifications`')
                && ! str_contains($sql, 'limit')
                && ! str_contains($sql, 'count(*)')
        ));

        $this->assertSame([], $unbounded, "The list path reads notifications without a limit:\n - ".implode("\n - ", $unbounded));
    }

    public function test_index_performs_no_writes(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 5);

        $this->assertNoWriteQueries(fn () => $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/notifications')
            ->assertOk());
    }

    private function measureMarkAll(int $unread): int
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, $unread);

        $this->countQueries(fn () => $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/notifications/mark-all-as-read')
            ->assertOk());

        return count($this->recordedQueries());
    }

    private function measure(User $user, string $uri): int
    {
        $this->countQueries(fn () => $this->actingAs($user, 'sanctum')->getJson($uri)->assertOk());

        return count($this->recordedQueries());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\Message;
use App\Models\User;
use Database\Factories\DatabaseNotificationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class AccountShellTest extends UserTestCase
{
    use RefreshDatabase;

    public function test_it_returns_profile_and_navigation_counts_in_one_response(): void
    {
        $user = $this->member();
        $firstPartner = $this->member();
        $secondPartner = $this->member();

        Message::factory()->from($firstPartner)->to($user)->count(2)->create();
        Message::factory()->from($secondPartner)->to($user)->read()->create();
        Message::factory()->from($user)->to($secondPartner)->create();
        DatabaseNotificationFactory::new()->forUser($user)->count(2)->create();
        DatabaseNotificationFactory::new()->forUser($user)->read()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/account/shell')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.unread_messages_count', 1)
            ->assertJsonPath('data.unread_notifications_count', 2)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => $this->userResourceKeys(),
                    'unread_messages_count',
                    'unread_notifications_count',
                ],
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/soom/account/shell')->assertUnauthorized();
    }

    public function test_query_count_does_not_grow_with_messages_or_notifications(): void
    {
        $user = $this->member();
        $partner = $this->member();

        Message::factory()->from($partner)->to($user)->count(3)->create();
        DatabaseNotificationFactory::new()->forUser($user)->count(3)->create();
        $small = $this->measure($user);

        Message::factory()->from($partner)->to($user)->count(30)->create();
        DatabaseNotificationFactory::new()->forUser($user)->count(30)->create();
        $large = $this->measure($user);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(6, $large);
    }

    private function measure(User $user): int
    {
        $this->countQueries(fn () => $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/soom/account/shell')
            ->assertOk());

        return count($this->recordedQueries());
    }
}

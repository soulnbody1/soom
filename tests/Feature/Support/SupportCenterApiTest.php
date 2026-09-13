<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Events\Support\SupportMessageCreated;
use App\Events\Support\SupportTicketUpdated;
use App\Models\Support\SupportCategory;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupportCenterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([SupportMessageCreated::class, SupportTicketUpdated::class]);
    }

    public function test_user_can_create_and_read_own_support_ticket(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $category = SupportCategory::query()->where('code', 'technical')->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/soom/support/tickets', [
            'category_id' => $category->public_id,
            'subject' => 'مشكلة في التطبيق',
            'message' => 'لا أستطيع فتح صفحة المزاد.',
            'client_message_id' => (string) Str::uuid(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subject', 'مشكلة في التطبيق')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.category.code', 'technical');

        $ticketId = $response->json('data.id');

        $this->actingAs($user, 'sanctum')->getJson("/api/soom/support/tickets/{$ticketId}")
            ->assertOk()->assertJsonPath('data.id', $ticketId);

        $this->actingAs($user, 'sanctum')->getJson("/api/soom/support/tickets/{$ticketId}/messages")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.visibility', 'public');

        Event::assertDispatched(SupportMessageCreated::class);
    }

    public function test_user_cannot_access_another_users_ticket(): void
    {
        [$owner, $intruder] = User::factory()->count(2)->create(['role' => 'user']);
        $ticket = $this->ticketFor($owner);

        $this->actingAs($intruder, 'sanctum')->getJson("/api/soom/support/tickets/{$ticket->public_id}")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')->postJson("/api/soom/support/tickets/{$ticket->public_id}/messages", ['message' => 'محاولة وصول'])
            ->assertForbidden();
    }

    public function test_internal_notes_never_appear_in_customer_api(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticketFor($owner);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/support/tickets/{$ticket->public_id}/internal-notes", [
            'message' => 'ملاحظة سرية للتصعيد.',
        ])->assertCreated()->assertJsonPath('data.visibility', 'internal');

        $this->actingAs($owner, 'sanctum')->getJson("/api/soom/support/tickets/{$ticket->public_id}/messages")
            ->assertOk()->assertJsonMissing(['body' => 'ملاحظة سرية للتصعيد.']);

        $this->actingAs($admin, 'sanctum')->getJson("/api/admin/support/tickets/{$ticket->public_id}/messages")
            ->assertOk()->assertJsonFragment(['body' => 'ملاحظة سرية للتصعيد.']);
    }

    public function test_message_client_id_is_idempotent_for_same_author(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $ticket = $this->ticketFor($owner);
        $clientId = (string) Str::uuid();
        $payload = ['message' => 'الرسالة نفسها', 'client_message_id' => $clientId];

        $this->actingAs($owner, 'sanctum')->postJson("/api/soom/support/tickets/{$ticket->public_id}/messages", $payload)->assertCreated();
        $this->actingAs($owner, 'sanctum')->postJson("/api/soom/support/tickets/{$ticket->public_id}/messages", $payload)->assertCreated();

        $this->assertSame(1, SupportMessage::query()->where('author_id', $owner->id)->where('client_message_id', $clientId)->count());
    }

    public function test_admin_update_rejects_stale_version(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticketFor($owner);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/support/tickets/{$ticket->public_id}", [
            'expected_version' => 1,
            'priority' => 'urgent',
            'assigned_to' => $admin->id,
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.priority', 'urgent');

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/support/tickets/{$ticket->public_id}", [
            'expected_version' => 1,
            'status' => 'closed',
        ])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    }

    private function ticketFor(User $owner): SupportTicket
    {
        $category = SupportCategory::query()->firstOrFail();

        return SupportTicket::create([
            'public_id' => (string) Str::ulid(),
            'reference_number' => 'SUP-TEST-'.Str::upper(Str::random(6)),
            'requester_id' => $owner->id,
            'category_id' => $category->id,
            'subject' => 'طلب مساعدة',
            'status' => 'new',
            'priority' => 'normal',
            'last_message_at' => now(),
            'first_response_due_at' => now()->addHour(),
            'resolution_due_at' => now()->addDay(),
        ]);
    }
}

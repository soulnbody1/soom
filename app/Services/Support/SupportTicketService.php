<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Domain\Support\Enums\SupportAuthorType;
use App\Domain\Support\Enums\SupportMessageVisibility;
use App\Domain\Support\Enums\SupportTicketStatus;
use App\Events\Support\SupportMessageCreated;
use App\Events\Support\SupportTicketUpdated;
use App\Models\Support\SupportCategory;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketEvent;
use App\Models\Support\SupportTicketRead;
use App\Models\User;
use App\Services\Notification\PushDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SupportTicketService
{
    public function __construct(private readonly PushDispatcher $push) {}

    public function create(User $requester, array $data): SupportTicket
    {
        $existing = $this->idempotentMessage($requester, $data['client_message_id'] ?? null);
        if ($existing) {
            return $existing->ticket->load(['category', 'requester', 'assignee']);
        }

        $category = SupportCategory::query()->where('public_id', $data['category_id'])->where('is_active', true)->firstOrFail();

        $ticket = DB::transaction(function () use ($requester, $data, $category): SupportTicket {
            $now = now();
            $ticket = SupportTicket::create([
                'public_id' => (string) Str::ulid(),
                'reference_number' => $this->referenceNumber(),
                'requester_id' => $requester->id,
                'category_id' => $category->id,
                'subject' => $data['subject'],
                'status' => SupportTicketStatus::New,
                'priority' => $category->default_priority,
                'context_type' => $data['context_type'] ?? null,
                'context_id' => $data['context_id'] ?? null,
                'first_response_due_at' => $now->copy()->addMinutes($category->first_response_minutes),
                'resolution_due_at' => $now->copy()->addMinutes($category->resolution_minutes),
                'last_message_at' => $now,
            ]);

            $message = $this->createMessage($ticket, $requester, SupportAuthorType::Customer, SupportMessageVisibility::Public, $data['message'], $data['client_message_id'] ?? null);
            $ticket->update(['last_message_id' => $message->id, 'last_message_at' => $message->created_at]);
            $this->event($ticket, $requester, 'ticket_created', ['category' => $category->code]);

            return $ticket;
        });

        $ticket->load(['category', 'requester', 'assignee']);
        $firstMessage = $ticket->messages()->with(['ticket', 'author'])->first();
        if ($firstMessage) {
            $this->broadcastMessage($firstMessage);
        }
        $this->broadcastTicket($ticket);
        $this->notifyAgents($ticket, 'طلب دعم جديد', 'تم إنشاء تذكرة دعم جديدة وتحتاج إلى المراجعة.');

        return $ticket;
    }

    public function addCustomerMessage(SupportTicket $ticket, User $user, array $data): SupportMessage
    {
        $existing = $this->idempotentMessage($user, $data['client_message_id'] ?? null);
        if ($existing) {
            $this->assertIdempotentTicket($existing, $ticket);

            return $existing->load(['ticket', 'author']);
        }

        if (! $ticket->status->acceptsCustomerMessages()) {
            throw ValidationException::withMessages(['ticket' => 'لا يمكن إرسال رسائل إلى تذكرة مغلقة.']);
        }

        $message = DB::transaction(function () use ($ticket, $user, $data): SupportMessage {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if (! $locked->status->acceptsCustomerMessages()) {
                throw ValidationException::withMessages(['ticket' => 'لا يمكن إرسال رسائل إلى تذكرة مغلقة.']);
            }
            $message = $this->createMessage($locked, $user, SupportAuthorType::Customer, SupportMessageVisibility::Public, $data['message'], $data['client_message_id'] ?? null);
            $updates = ['last_message_id' => $message->id, 'last_message_at' => $message->created_at, 'version' => $locked->version + 1];
            if (in_array($locked->status, [SupportTicketStatus::WaitingCustomer, SupportTicketStatus::Resolved], true)) {
                $updates['status'] = SupportTicketStatus::Open;
                $updates['reopened_at'] = now();
                $updates['resolved_at'] = null;
            }
            $locked->update($updates);
            $this->event($locked, $user, 'customer_message_created');

            return $message;
        });

        $this->broadcastMessage($message->load(['ticket', 'author']));
        $this->broadcastTicket($message->ticket);
        $this->notifyAgents($message->ticket, 'رسالة جديدة في الدعم', 'أضاف العميل تحديثًا جديدًا إلى تذكرة الدعم.');

        return $message;
    }

    public function addAgentMessage(SupportTicket $ticket, User $agent, array $data, bool $internal): SupportMessage
    {
        $existing = $this->idempotentMessage($agent, $data['client_message_id'] ?? null);
        if ($existing) {
            $this->assertIdempotentTicket($existing, $ticket);

            return $existing->load(['ticket', 'author']);
        }

        if ($ticket->status === SupportTicketStatus::Closed) {
            throw ValidationException::withMessages(['ticket' => 'لا يمكن تعديل تذكرة مغلقة.']);
        }

        $message = DB::transaction(function () use ($ticket, $agent, $data, $internal): SupportMessage {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->status === SupportTicketStatus::Closed) {
                throw ValidationException::withMessages(['ticket' => 'لا يمكن تعديل تذكرة مغلقة.']);
            }
            $visibility = $internal ? SupportMessageVisibility::Internal : SupportMessageVisibility::Public;
            $message = $this->createMessage($locked, $agent, SupportAuthorType::Agent, $visibility, $data['message'], $data['client_message_id'] ?? null);
            $updates = ['version' => $locked->version + 1];
            if (! $internal) {
                $updates['last_message_id'] = $message->id;
                $updates['last_message_at'] = $message->created_at;
                $updates['status'] = SupportTicketStatus::WaitingCustomer;
                if ($locked->first_responded_at === null) {
                    $updates['first_responded_at'] = now();
                }
            }
            if ($locked->assigned_to === null) {
                $updates['assigned_to'] = $agent->id;
            }
            $locked->update($updates);
            $this->event($locked, $agent, $internal ? 'internal_note_created' : 'agent_message_created');

            return $message;
        });

        $this->broadcastMessage($message->load(['ticket', 'author']));
        $this->broadcastTicket($message->ticket);
        if (! $internal) {
            $this->notifyCustomer($message->ticket, 'تحديث على طلب الدعم', 'لديك رد جديد من فريق الدعم.');
        }

        return $message;
    }

    public function update(SupportTicket $ticket, User $agent, array $data): SupportTicket
    {
        $updated = DB::transaction(function () use ($ticket, $agent, $data): SupportTicket {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ((int) $data['expected_version'] !== (int) $locked->version) {
                throw ValidationException::withMessages(['expected_version' => 'تم تعديل التذكرة بواسطة موظف آخر. حدّث البيانات وحاول مجددًا.']);
            }

            if (array_key_exists('status', $data)) {
                $nextStatus = SupportTicketStatus::from($data['status']);
                if (! $locked->status->canTransitionTo($nextStatus)) {
                    throw ValidationException::withMessages(['status' => 'انتقال حالة التذكرة غير مسموح.']);
                }
            }

            $changes = [];
            foreach (['status', 'priority', 'assigned_to'] as $field) {
                $current = in_array($field, ['status', 'priority'], true) ? $locked->{$field}->value : $locked->{$field};
                if (array_key_exists($field, $data) && (string) $current !== (string) ($data[$field] ?? '')) {
                    $changes[$field] = ['from' => $current, 'to' => $data[$field]];
                }
            }
            $updates = array_intersect_key($data, array_flip(['status', 'priority', 'assigned_to']));
            if (($updates['status'] ?? null) === SupportTicketStatus::Resolved->value) {
                $updates['resolved_at'] = now();
            }
            if (($updates['status'] ?? null) === SupportTicketStatus::Open->value) {
                $updates['resolved_at'] = null;
                $updates['reopened_at'] = now();
            }
            if (($updates['status'] ?? null) === SupportTicketStatus::Closed->value) {
                $updates['closed_at'] = now();
            }
            $updates['version'] = $locked->version + 1;
            $locked->update($updates);
            $this->event($locked, $agent, 'ticket_updated', $changes);

            return $locked;
        });

        $this->broadcastTicket($updated);

        return $updated->load(['category', 'requester', 'assignee']);
    }

    public function resolve(SupportTicket $ticket, User $user): SupportTicket
    {
        if ($ticket->status === SupportTicketStatus::Resolved) {
            return $ticket->load(['category', 'requester', 'assignee']);
        }
        if (! $ticket->status->canTransitionTo(SupportTicketStatus::Resolved)) {
            throw ValidationException::withMessages(['ticket' => 'لا يمكن اعتبار هذه التذكرة محلولة.']);
        }

        return $this->changeCustomerStatus($ticket, $user, SupportTicketStatus::Resolved);
    }

    public function reopen(SupportTicket $ticket, User $user): SupportTicket
    {
        if ($ticket->status !== SupportTicketStatus::Resolved || $ticket->resolved_at?->lt(now()->subDays((int) config('support_chat.reopen_window_days')))) {
            throw ValidationException::withMessages(['ticket' => 'هذه التذكرة غير متاحة لإعادة الفتح.']);
        }

        return $this->changeCustomerStatus($ticket, $user, SupportTicketStatus::Open);
    }

    public function markRead(SupportTicket $ticket, User $user): SupportTicketRead
    {
        $last = SupportMessage::query()->where('ticket_id', $ticket->id)
            ->when($user->role !== 'admin', fn ($query) => $query->where('visibility', SupportMessageVisibility::Public->value))
            ->max('id');

        return SupportTicketRead::updateOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $user->id],
            ['last_read_message_id' => $last, 'read_at' => now()]
        );
    }

    private function changeCustomerStatus(SupportTicket $ticket, User $user, SupportTicketStatus $status): SupportTicket
    {
        $updates = ['status' => $status, 'version' => $ticket->version + 1];
        $updates[$status === SupportTicketStatus::Resolved ? 'resolved_at' : 'reopened_at'] = now();
        if ($status === SupportTicketStatus::Open) {
            $updates['resolved_at'] = null;
        }
        $ticket->update($updates);
        $this->event($ticket, $user, $status === SupportTicketStatus::Resolved ? 'ticket_resolved' : 'ticket_reopened');
        $this->broadcastTicket($ticket);

        return $ticket->fresh(['category', 'requester', 'assignee']);
    }

    private function createMessage(SupportTicket $ticket, User $author, SupportAuthorType $type, SupportMessageVisibility $visibility, string $body, ?string $clientId): SupportMessage
    {
        return SupportMessage::create(['public_id' => (string) Str::ulid(), 'ticket_id' => $ticket->id, 'author_id' => $author->id, 'author_type' => $type, 'visibility' => $visibility, 'body' => $body, 'client_message_id' => $clientId]);
    }

    private function idempotentMessage(User $author, ?string $clientId): ?SupportMessage
    {
        return $clientId ? SupportMessage::query()->where('author_id', $author->id)->where('client_message_id', $clientId)->first() : null;
    }

    private function assertIdempotentTicket(SupportMessage $message, SupportTicket $ticket): void
    {
        if ((int) $message->ticket_id !== (int) $ticket->id) {
            throw ValidationException::withMessages(['client_message_id' => 'معرّف الرسالة مستخدم لطلب آخر.']);
        }
    }

    private function broadcastMessage(SupportMessage $message): void
    {
        try {
            event(new SupportMessageCreated($message));
        } catch (Throwable $exception) {
            Log::warning('Support message broadcast failed.', ['message_id' => $message->id, 'exception' => $exception->getMessage()]);
        }
    }

    private function broadcastTicket(SupportTicket $ticket): void
    {
        try {
            event(new SupportTicketUpdated($ticket));
        } catch (Throwable $exception) {
            Log::warning('Support ticket broadcast failed.', ['ticket_id' => $ticket->id, 'exception' => $exception->getMessage()]);
        }
    }

    private function notifyCustomer(SupportTicket $ticket, string $title, string $body): void
    {
        try {
            $this->push->toUser((int) $ticket->requester_id, $title, $body, $this->pushData($ticket));
        } catch (Throwable $exception) {
            Log::warning('Support customer push failed.', ['ticket_id' => $ticket->id, 'exception' => $exception->getMessage()]);
        }
    }

    private function notifyAgents(SupportTicket $ticket, string $title, string $body): void
    {
        try {
            $recipients = $ticket->assigned_to
                ? [(int) $ticket->assigned_to]
                : User::query()->where('role', 'admin')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($recipients !== []) {
                $this->push->toUsers($recipients, $title, $body, $this->pushData($ticket));
            }
        } catch (Throwable $exception) {
            Log::warning('Support agent push failed.', ['ticket_id' => $ticket->id, 'exception' => $exception->getMessage()]);
        }
    }

    private function pushData(SupportTicket $ticket): array
    {
        return [
            'type' => 'support_ticket',
            'ticket_id' => $ticket->public_id,
            'reference_number' => $ticket->reference_number,
        ];
    }

    private function event(SupportTicket $ticket, User $actor, string $type, array $metadata = []): void
    {
        SupportTicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $actor->id, 'type' => $type, 'metadata' => $metadata ?: null]);
    }

    private function referenceNumber(): string
    {
        do {
            $reference = 'SUP-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (SupportTicket::query()->where('reference_number', $reference)->exists());

        return $reference;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Message\Actions;

use App\Events\ConversationUpdated;
use App\Jobs\Message\BroadcastConversationUpdate;
use App\Models\Message;
use App\Models\User;
use App\Policies\MessagePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteMessagesAction
{
    public function __construct() {}

    public function execute(User $actor, int $partnerId, ?array $messageIds = null): void
    {
        $userId = (int) $actor->id;

        $partnerIds = $messageIds === null || $messageIds === []
            ? $this->deleteThread($userId, $partnerId)
            : $this->deleteMessages($actor, $messageIds);

        $this->announce($userId, $partnerIds);
    }

    private function deleteMessages(User $actor, array $messageIds): Collection
    {
        $userId = (int) $actor->id;
        $ids = array_values(array_unique(array_map('intval', $messageIds)));

        $messages = Message::query()
            ->select(['id', 'sender_id', 'receiver_id', 'created_at'])
            ->whereIn('id', $ids)
            ->get();

        $this->assertOwnership($actor, $messages);

        $recallable = $messages
            ->filter(fn (Message $message): bool => Gate::forUser($actor)->allows('recall', $message))
            ->pluck('id')
            ->all();

        $hidden = $messages
            ->reject(fn (Message $message): bool => in_array($message->id, $recallable, true))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($userId, $recallable, $hidden): void {
            if ($recallable !== []) {
                Message::whereIn('id', $recallable)->delete();
            }

            $this->hide($userId, $hidden);
        });

        return $messages
            ->map(fn (Message $message): int => $message->sender_id === $userId ? $message->receiver_id : $message->sender_id)
            ->unique()
            ->values();
    }

    private function deleteThread(int $userId, int $partnerId): Collection
    {
        $cutoff = MessagePolicy::recallCutoff();

        DB::transaction(function () use ($userId, $partnerId, $cutoff): void {
            $this->thread($userId, $partnerId)
                ->where('sender_id', $userId)
                ->where('created_at', '>=', $cutoff)
                ->delete();

            DB::table('message_deletions')->insertOrIgnoreUsing(
                ['user_id', 'message_id', 'created_at', 'updated_at'],
                $this->thread($userId, $partnerId)
                    ->toBase()
                    ->select([DB::raw((string) $userId), 'messages.id', DB::raw('now()'), DB::raw('now()')])
            );
        });

        return new Collection([$partnerId]);
    }

    private function announce(int $userId, Collection $partnerIds): void
    {
        foreach ($partnerIds as $index => $partnerId) {
            BroadcastConversationUpdate::dispatch(
                $userId,
                (int) $partnerId,
                $index === 0,
                ConversationUpdated::DELETED
            );
        }
    }

    private function assertOwnership(User $actor, Collection $messages): void
    {
        foreach ($messages as $message) {
            Gate::forUser($actor)->authorize('delete', $message);
        }
    }

    private function hide(int $userId, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        DB::table('message_deletions')->insertOrIgnore(array_map(fn (int $messageId): array => [
            'user_id' => $userId,
            'message_id' => $messageId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $messageIds));
    }

    private function thread(int $userId, int $partnerId): Builder
    {
        return Message::query()->where(function (Builder $query) use ($userId, $partnerId): void {
            $query
                ->where(fn (Builder $side) => $side->where('sender_id', $userId)->where('receiver_id', $partnerId))
                ->orWhere(fn (Builder $side) => $side->where('sender_id', $partnerId)->where('receiver_id', $userId));
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Message\Actions;

use App\Events\ConversationUpdatedAfterDelete;
use App\Events\UnreadCountUpdated;
use App\Models\Message;
use App\Repositories\Message\Queries\ConversationThreadsQuery;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DeleteMessagesAction
{
    public const RECALL_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly ConversationThreadsQuery $threads,
        private readonly UnreadConversationCounter $unread,
    ) {}

    public function execute(int $userId, int $partnerId, ?array $messageIds = null): void
    {
        $partnerIds = $messageIds === null || $messageIds === []
            ? $this->deleteThread($userId, $partnerId)
            : $this->deleteMessages($userId, $messageIds);

        $this->announce($userId, $partnerIds);
    }

    private function deleteMessages(int $userId, array $messageIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));

        $messages = Message::query()
            ->select(['id', 'sender_id', 'receiver_id', 'created_at'])
            ->whereIn('id', $ids)
            ->get();

        $this->assertOwnership($userId, $messages);

        $cutoff = $this->cutoff();

        $recallable = $messages
            ->filter(fn (Message $message): bool => $this->isRecallable($userId, $message, $cutoff))
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
        $cutoff = $this->cutoff();

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
        foreach ($partnerIds as $partnerId) {
            $conversation = $this->threads->forPartner($userId, (int) $partnerId);

            if ($conversation) {
                event(new ConversationUpdatedAfterDelete($conversation, $userId));
            }
        }

        event(new UnreadCountUpdated($userId, $this->unread->forUser($userId)));
    }

    private function assertOwnership(int $userId, Collection $messages): void
    {
        $foreign = $messages->first(
            fn (Message $message): bool => $message->sender_id !== $userId && $message->receiver_id !== $userId
        );

        if ($foreign !== null) {
            throw new AuthorizationException('لا يمكنك حذف رسائل لا تخصك.');
        }
    }

    private function isRecallable(int $userId, Message $message, Carbon $cutoff): bool
    {
        return $message->sender_id === $userId && $message->created_at->greaterThanOrEqualTo($cutoff);
    }

    private function cutoff(): Carbon
    {
        return now()->subSeconds(self::RECALL_WINDOW_SECONDS);
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

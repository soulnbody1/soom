<?php

declare(strict_types=1);

namespace Tests\Feature\Message\Concerns;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

trait CreatesChatFixtures
{
    protected function chatUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    protected function sendFixture(User $sender, User $receiver, array $overrides = []): Message
    {
        return Message::factory()->from($sender)->to($receiver)->create($overrides);
    }

    /**
     * @return Collection<int, Message>
     */
    protected function makeThread(User $viewer, User $partner, int $inbound = 1, int $outbound = 1): Collection
    {
        $messages = new Collection;

        for ($i = 0; $i < $outbound; $i++) {
            $messages->push($this->sendFixture($viewer, $partner));
        }

        for ($i = 0; $i < $inbound; $i++) {
            $messages->push($this->sendFixture($partner, $viewer));
        }

        return $messages;
    }

    /**
     * @return Collection<int, User>
     */
    protected function makeThreads(User $viewer, int $count, int $inbound = 1, int $outbound = 1): Collection
    {
        $partners = new Collection;

        for ($i = 0; $i < $count; $i++) {
            $partner = $this->chatUser();
            $this->makeThread($viewer, $partner, $inbound, $outbound);
            $partners->push($partner);
        }

        return $partners;
    }

    protected function hideFromUser(User $user, Message $message): void
    {
        DB::table('message_deletions')->insert([
            'user_id' => $user->id,
            'message_id' => $message->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

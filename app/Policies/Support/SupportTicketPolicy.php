<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Models\Support\SupportTicket;
use App\Models\User;

final class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->role === 'admin' || (int) $ticket->requester_id === (int) $user->id;
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}

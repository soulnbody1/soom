<?php

use App\Models\User;
use App\Models\Support\SupportTicket;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{user1}.{user2}', function ($user, $user1, $user2) {
    return (int) $user->id === (int) $user1 || (int) $user->id === (int) $user2
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('presence.online-users', function (User $user) {
    return ['id' => $user->id, 'name' => $user->name];
});

Broadcast::channel('conversations.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('support.ticket.{publicId}', function (User $user, string $publicId) {
    if ($user->role === 'admin') {
        return true;
    }

    return SupportTicket::query()->where('public_id', $publicId)->where('requester_id', $user->id)->exists();
});

Broadcast::channel('support.queue', fn (User $user): bool => $user->role === 'admin');

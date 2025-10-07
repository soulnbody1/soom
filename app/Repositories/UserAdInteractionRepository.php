<?php

namespace App\Repositories;

use App\Models\UserAdInteraction;

class UserAdInteractionRepository
{
    public function store(array $data): UserAdInteraction
    {
        return UserAdInteraction::create($data);
    }

    public function countUserInteractionsByCategory(int $userId, int $categoryId): int
    {
        return UserAdInteraction::where('user_id', $userId)
            ->whereIn('action', ['click', 'save'])
            ->whereHas('ad', fn($q) => $q->where('category_id', $categoryId))
            ->count();
    }

    public function interactionExists(int $user_id, int $ad_id, string $action): bool
    {
        return UserAdInteraction::where(compact('user_id', 'ad_id', 'action'))->exists();
    }
}

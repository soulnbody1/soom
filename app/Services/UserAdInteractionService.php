<?php

namespace App\Services;

use App\Repositories\UserAdInteractionRepository;

class UserAdInteractionService
{
    public function __construct(protected UserAdInteractionRepository $repo) {}

    public function store(int $ad_id, string $action)
    {
        if (!in_array($action, ['click', 'save'])) {
            return;
        }

        $userId = auth('sanctum')->id();
        if (!is_int($userId)) {
            return;
        }

        $exists = $this->repo->interactionExists($userId, $ad_id, $action);
        if ($exists) return;

        $this->repo->store([
            'user_id' => $userId,
            'ad_id' => $ad_id,
            'action' => $action,
        ]);
    }
}

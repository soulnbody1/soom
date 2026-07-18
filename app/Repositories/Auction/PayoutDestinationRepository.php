<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\PayoutDestination;
use Illuminate\Database\Eloquent\Collection;

final class PayoutDestinationRepository
{
    public function listFor(int $userId): Collection
    {
        return PayoutDestination::where('user_id', $userId)
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();
    }

    public function defaultFor(int $userId): ?PayoutDestination
    {
        return PayoutDestination::where('user_id', $userId)
            ->where('default_marker', 1)
            ->first();
    }

    public function clearDefaultFor(int $userId): void
    {
        PayoutDestination::where('user_id', $userId)
            ->where('default_marker', 1)
            ->lockForUpdate()
            ->get()
            ->each(fn (PayoutDestination $destination) => $destination
                ->forceFill(['is_default' => false, 'default_marker' => null])
                ->save());
    }

    public function save(PayoutDestination $destination): void
    {
        $destination->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Models\Auction\Auction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class AuctionParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user && Gate::forUser($user)->allows('viewAny', Auction::class);

        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'status' => $this->status->value,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'qualified_at' => $this->qualified_at?->toIso8601String(),
            'user' => $this->when(
                $isAdmin && $this->relationLoaded('user') && $this->user,
                fn () => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ]
            ),
            'blocked_at' => $this->when($isAdmin, $this->blocked_at?->toIso8601String()),
            'block_reason' => $this->when($isAdmin, $this->block_reason),
        ];
    }
}

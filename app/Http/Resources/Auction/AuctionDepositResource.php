<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Models\Auction\PaymentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class AuctionDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReviewPayments = $user && Gate::forUser($user)->allows('viewAny', PaymentSubmission::class);

        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'type' => $this->type,
            'status' => $this->status->value,
            'required_amount' => MoneyResource::make($this->required_amount_minor, $this->currency_code),
            'held_amount' => MoneyResource::make($this->held_amount_minor, $this->currency_code),
            'applied_amount' => MoneyResource::make($this->applied_amount_minor, $this->currency_code),
            'refunded_amount' => MoneyResource::make($this->refunded_amount_minor, $this->currency_code),
            'user' => $this->when(
                $canReviewPayments && $this->relationLoaded('user') && $this->user,
                fn () => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ]
            ),
        ];
    }
}

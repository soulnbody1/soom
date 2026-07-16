<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class PaymentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReviewPayments = $user && Gate::forUser($user)->allows('viewAny', \App\Models\Auction\PaymentSubmission::class);

        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'amount' => MoneyResource::make($this->amount_minor, $this->currency_code),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->public_id,
                'name' => $this->paymentMethod->name,
                'code' => $this->paymentMethod->code,
            ]),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'user' => $this->when(
                $canReviewPayments && $this->relationLoaded('user') && $this->user,
                fn () => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ]
            ),
            'review_note' => $this->when(
                $canReviewPayments || $user?->id === $this->user_id,
                $this->review_note
            ),
            'provider_reference' => $this->when($canReviewPayments, $this->provider_reference),
            'transaction' => $this->when(
                $canReviewPayments && $this->relationLoaded('transaction') && $this->transaction,
                fn () => [
                    'id' => $this->transaction->public_id,
                    'status' => $this->transaction->status->value,
                    'amount' => MoneyResource::make($this->transaction->amount_minor, $this->transaction->currency_code),
                    'provider' => $this->transaction->provider,
                    'provider_transaction_id' => $this->transaction->provider_transaction_id,
                    'processed_at' => $this->transaction->processed_at?->toIso8601String(),
                ]
            ),
        ];
    }
}

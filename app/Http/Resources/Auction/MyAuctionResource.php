<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

final class MyAuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $currentAmountMinor = $this->currentAmountMinor();

        $data = [
            'id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => __('auction.statuses.' . $this->status->value),
            'currency_code' => $this->currency_code,
            'starting_amount' => MoneyResource::make($this->starting_amount_minor, $this->currency_code),
            'current_amount' => MoneyResource::make($currentAmountMinor, $this->currency_code),
            'minimum_next_bid' => MoneyResource::make(
                $this->relationLoaded('currentLeadingBid') && $this->currentLeadingBid
                    ? $currentAmountMinor + (int) $this->minimum_bid_increment_minor
                    : (int) $this->starting_amount_minor,
                $this->currency_code
            ),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'extension' => [
                'count' => $this->extension_count,
                'last_extended_at' => $this->last_extended_at?->toIso8601String(),
            ],
            'images' => AuctionMediaResource::collection($this->whenLoaded('media')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country->id,
                'name' => $this->country->name,
            ]),
            'state' => $this->whenLoaded('state', fn () => $this->state ? [
                'id' => $this->state->id,
                'name' => $this->state->name,
            ] : null),
            'city' => $this->whenLoaded('city', fn () => $this->city ? [
                'id' => $this->city->id,
                'name' => $this->city->name,
            ] : null),
        ];

        if ($user && $this->relationLoaded('bids')) {
            $data['my_bids'] = AuctionBidResource::collection($this->bids);
        }

        if ($user && $this->relationLoaded('deposits')) {
            $deposits = $this->deposits;
            $data['my_deposits'] = AuctionDepositResource::collection($deposits);
            $data['my_payment_submissions'] = PaymentSubmissionResource::collection($this->paymentSubmissionsFrom($deposits));
            $data['my_refunds'] = $this->refundsFrom($deposits);
        }

        if ($user?->id === $this->seller_id && $this->relationLoaded('sellerDeposit')) {
            $sellerDeposit = $this->sellerDeposit;
            $data['seller_deposit'] = $sellerDeposit ? new AuctionDepositResource($sellerDeposit) : null;
            $data['seller_payment_submissions'] = $sellerDeposit
                ? PaymentSubmissionResource::collection($this->paymentSubmissionsFrom(collect([$sellerDeposit])))
                : [];
        }

        if ($user?->id === $this->seller_id && $this->relationLoaded('settlement')) {
            $data['seller_financial_summary'] = $this->settlement ? [
                'status' => $this->settlement->status->value,
                'winning_amount' => MoneyResource::make($this->settlement->winning_amount_minor, $this->currency_code),
                'seller_net_amount' => MoneyResource::make($this->settlement->seller_net_amount_minor, $this->currency_code),
                'platform_fee' => MoneyResource::make($this->settlement->platform_fee_minor, $this->currency_code),
                'completed_at' => $this->settlement->completed_at?->toIso8601String(),
                'payout' => $this->settlement->relationLoaded('sellerPayout') && $this->settlement->sellerPayout
                    ? SellerPayoutResource::sellerPayload($this->settlement->sellerPayout)
                    : null,
            ] : null;
            $data['handover_status'] = $this->settlement ? [
                'payment_due_at' => $this->settlement->payment_due_at?->toIso8601String(),
                'handover_due_at' => $this->settlement->handover_due_at?->toIso8601String(),
                'seller_handover_confirmed_at' => $this->settlement->seller_handover_confirmed_at?->toIso8601String(),
                'buyer_receipt_confirmed_at' => $this->settlement->buyer_receipt_confirmed_at?->toIso8601String(),
                'handover_completed_at' => $this->settlement->handover_completed_at?->toIso8601String(),
            ] : null;
        }

        if ($user && $this->relationLoaded('settlement') && $this->settlement?->winner_id === $user->id) {
            $data['winner_settlement'] = [
                'status' => $this->settlement->status->value,
                'amount_due' => MoneyResource::make($this->settlement->amount_due_minor, $this->currency_code),
                'amount_paid' => MoneyResource::make($this->settlement->amount_paid_minor, $this->currency_code),
                'remaining_amount' => MoneyResource::make($this->settlement->remaining_amount_minor, $this->currency_code),
                'payment_due_at' => $this->settlement->payment_due_at?->toIso8601String(),
            ];
        }

        return $data;
    }

    private function currentAmountMinor(): int
    {
        if (! $this->relationLoaded('currentLeadingBid')) {
            return (int) $this->starting_amount_minor;
        }

        return (int) ($this->currentLeadingBid?->amount_minor ?? $this->starting_amount_minor);
    }

    private function paymentSubmissionsFrom(Collection $deposits): Collection
    {
        return $deposits
            ->filter(fn ($deposit): bool => $deposit->relationLoaded('paymentSubmissions'))
            ->flatMap(fn ($deposit): Collection => $deposit->paymentSubmissions);
    }

    private function refundsFrom(Collection $deposits): array
    {
        return $this->paymentSubmissionsFrom($deposits)
            ->filter(fn ($submission): bool => $submission->relationLoaded('transaction') && $submission->transaction?->relationLoaded('refunds'))
            ->flatMap(fn ($submission): Collection => $submission->transaction->refunds)
            ->map(fn ($refund): array => [
                'id' => $refund->public_id,
                'status' => $refund->status->value,
                'amount' => MoneyResource::make($refund->amount_minor, $refund->currency_code),
                'processed_at' => $refund->processed_at?->toIso8601String(),
                'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
                'failed_at' => $refund->failed_at?->toIso8601String(),
                'cancelled_at' => $refund->cancelled_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Models\Auction\PaymentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;


final class AdminAuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReviewPayments = $user && Gate::forUser($user)->allows('viewAny', PaymentSubmission::class);
        $canManageSettlement = $user
            && $this->relationLoaded('settlement')
            && $this->settlement
            && Gate::forUser($user)->allows('override', $this->settlement);
        $canResolveDisputes = $user && Gate::forUser($user)->allows('resolveDispute', $this->resource);

        $data = [
            'id' => $this->public_id,
            'internal_id' => $this->id,
            'seller_id' => $this->seller_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => __('auction.statuses.' . $this->status->value),
            'currency_code' => $this->currency_code,
            'starting_amount' => MoneyResource::make($this->starting_amount_minor, $this->currency_code),
            'reserve_amount' => $this->reserve_amount_minor !== null
                ? MoneyResource::make($this->reserve_amount_minor, $this->currency_code)
                : null,
            'minimum_bid_increment' => MoneyResource::make($this->minimum_bid_increment_minor, $this->currency_code),
            'current_amount' => MoneyResource::make(
                $this->relationLoaded('currentLeadingBid')
                    ? ($this->currentLeadingBid?->amount_minor ?? $this->starting_amount_minor)
                    : $this->starting_amount_minor,
                $this->currency_code
            ),
            'reserve_met' => $this->reserve_amount_minor === null
                ? null
                : (($this->relationLoaded('currentLeadingBid') ? ($this->currentLeadingBid?->amount_minor ?? 0) : 0) >= $this->reserve_amount_minor),
            'winner_payment_deadline_hours' => $this->winner_payment_deadline_hours,
            'handover_deadline_hours' => $this->handover_deadline_hours,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'original_ends_at' => $this->original_ends_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'extension' => [
                'window_seconds' => $this->extension_window_seconds,
                'duration_seconds' => $this->extension_duration_seconds,
                'maximum_count' => $this->maximum_extension_count,
                'count' => $this->extension_count,
                'last_extended_at' => $this->last_extended_at?->toIso8601String(),
            ],
            'configuration_version_id' => $this->configuration_version_id,
            'published_at' => $this->published_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'media' => AuctionMediaResource::collection($this->whenLoaded('media')),
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
            'leading_bid' => new AuctionBidResource($this->whenLoaded('currentLeadingBid')),
            'winning_bid' => new AuctionBidResource($this->whenLoaded('winningBid')),
            'metrics' => $this->whenLoaded('metric', fn () => [
                'views_count' => $this->metric?->views_count ?? 0,
                'unique_views_count' => $this->metric?->unique_views_count ?? 0,
                'participants_count' => $this->metric?->participants_count ?? 0,
                'bids_count' => $this->metric?->bids_count ?? 0,
                'unique_bidders_count' => $this->metric?->unique_bidders_count ?? 0,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($canReviewPayments) {
            $data['deposits'] = AuctionDepositResource::collection($this->whenLoaded('deposits'));
            $data['payment_submissions'] = PaymentSubmissionResource::collection($this->paymentSubmissions());
            $refunds = $this->refunds($user);
            if ($refunds !== []) {
                $data['refunds'] = $refunds;
            }
        }

        if ($canManageSettlement) {
            $data['financial_details'] = [
                'seller_deposit_amount' => MoneyResource::make($this->seller_deposit_amount_minor, $this->currency_code),
                'bidder_deposit_amount' => MoneyResource::make($this->bidder_deposit_amount_minor, $this->currency_code),
                'platform_fee' => [
                    'type' => $this->platform_fee_type,
                    'basis_points' => $this->platform_fee_basis_points,
                    'fixed' => MoneyResource::make($this->platform_fee_fixed_minor, $this->currency_code),
                ],
                'settlement' => new AuctionSettlementResource($this->settlement),
            ];
        }

        if ($canResolveDisputes) {
            $data['disputes'] = $this->whenLoaded('disputes', fn () => $this->disputes->map(fn ($dispute): array => [
                'id' => $dispute->public_id,
                'status' => $dispute->status,
                'opened_by' => $dispute->opened_by,
                'resolution_note' => $dispute->resolution_note,
                'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            ])->values());
        }

        return $data;
    }

    private function paymentSubmissions(): Collection
    {
        if (! $this->relationLoaded('deposits')) {
            return collect();
        }

        return $this->deposits
            ->filter(fn ($deposit): bool => $deposit->relationLoaded('paymentSubmissions'))
            ->flatMap(fn ($deposit): Collection => $deposit->paymentSubmissions);
    }

    private function refunds(?object $user): array
    {
        if (! $user) {
            return [];
        }

        return $this->paymentSubmissions()
            ->filter(fn ($submission): bool => $submission->relationLoaded('transaction') && $submission->transaction?->relationLoaded('refunds'))
            ->flatMap(fn ($submission): Collection => $submission->transaction->refunds)
            ->filter(fn ($refund): bool => Gate::forUser($user)->allows('execute', $refund)
                || Gate::forUser($user)->allows('confirmManual', $refund)
                || Gate::forUser($user)->allows('cancel', $refund))
            ->map(fn ($refund): array => [
                'id' => $refund->public_id,
                'status' => $refund->status->value,
                'amount' => MoneyResource::make($refund->amount_minor, $refund->currency_code),
                'provider' => $refund->provider,
                'provider_refund_id' => $refund->provider_refund_id,
                'attempt_count' => $refund->attempt_count,
                'failure_reason' => $refund->failure_reason,
                'processed_at' => $refund->processed_at?->toIso8601String(),
                'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
                'failed_at' => $refund->failed_at?->toIso8601String(),
                'cancelled_at' => $refund->cancelled_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

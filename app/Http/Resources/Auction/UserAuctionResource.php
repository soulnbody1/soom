<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\DTO\Auction\ParticipationStateDTO;
use App\Http\Resources\Auction\Concerns\EmitsStableKeys;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

final class UserAuctionResource extends JsonResource
{
    use EmitsStableKeys;

    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $snapshot = $this->resource->relationLoaded('configurationSnapshot')
            ? $this->resource->configurationSnapshot
            : null;
        $settlement = $this->resource->relationLoaded('settlement') ? $this->resource->settlement : null;
        $isSeller = $viewer !== null && (int) $viewer->id === (int) $this->resource->seller_id;
        $isWinner = $viewer !== null && $settlement !== null && (int) $settlement->winner_id === (int) $viewer->id;
        $currentAmountMinor = $this->currentAmountMinor();
        $deposits = $this->resource->relationLoaded('deposits') ? $this->resource->deposits : collect();

        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'status' => $this->resource->status->value,
            'status_label' => __('auction.statuses.'.$this->resource->status->value),
            'currency_code' => $this->resource->currency_code,
            'starting_amount' => MoneyResource::make((int) $this->resource->starting_amount_minor, $this->resource->currency_code),
            'current_amount' => MoneyResource::make($currentAmountMinor, $this->resource->currency_code),
            'minimum_next_bid' => MoneyResource::make($this->minimumNextBidMinor($snapshot, $currentAmountMinor), $this->resource->currency_code),
            'reserve_met' => $this->reserveMet($currentAmountMinor),
            'images' => $this->stableList('media', AuctionMediaResource::class, $request),
            'category' => $this->stableRelation('category', fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
            ]),
            'location' => [
                'country' => $this->stableRelation('country', fn ($country) => ['id' => $country->id, 'name' => $country->name]),
                'state' => $this->stableRelation('state', fn ($state) => ['id' => $state->id, 'name' => $state->name]),
                'city' => $this->stableRelation('city', fn ($city) => ['id' => $city->id, 'name' => $city->name]),
            ],
            'seller' => $this->stableRelation('seller', fn ($seller) => [
                'name' => $seller->name,
                'is_me' => $isSeller,
            ]),
            'timeline' => $this->timeline($settlement),
            'metrics' => $this->metrics(),
            'participation_requirements' => $this->participationRequirements($snapshot),
            'my_participation' => $this->myParticipation(),
            'next_action' => $this->nextAction(),
            'my_bids' => $this->stableList('bids', AuctionBidResource::class, $request),
            'my_deposits' => $this->stableList('deposits', AuctionDepositResource::class, $request),
            'my_payment_submissions' => $this->stableCollection($this->paymentSubmissionsFrom($deposits), PaymentSubmissionResource::class, $request),
            'my_refunds' => $this->refundsFrom($deposits),
            'winner_settlement' => $isWinner ? $this->winnerSettlement($settlement) : null,
            'seller_context' => $isSeller ? $this->sellerContext($settlement, $request) : null,
            'handover_status' => ($isSeller || $isWinner) ? $this->handoverStatus($settlement) : null,
            'current_dispute' => $this->currentDispute($isSeller, $isWinner),
        ];
    }

    private function state(): ?ParticipationStateDTO
    {
        return $this->resource->participationState;
    }

    private function myParticipation(): array
    {
        $state = $this->state();

        if ($state === null) {
            return ParticipationStateResourceShape::guestShape((string) $this->resource->currency_code);
        }

        return ParticipationStateResourceShape::fromState($state);
    }

    private function nextAction(): array
    {
        $state = $this->state();

        return [
            'code' => $state?->nextAction->value ?? 'login',
            'allowed' => $state?->nextActionAllowed ?? true,
        ];
    }

    private function currentAmountMinor(): int
    {
        if (! $this->resource->relationLoaded('currentLeadingBid')) {
            return (int) $this->resource->starting_amount_minor;
        }

        return (int) ($this->resource->currentLeadingBid?->amount_minor ?? $this->resource->starting_amount_minor);
    }

    private function minimumNextBidMinor(?AuctionConfigurationSnapshot $snapshot, int $currentAmountMinor): int
    {
        $hasLeader = $this->resource->relationLoaded('currentLeadingBid') && $this->resource->currentLeadingBid;

        if (! $hasLeader) {
            return (int) $this->resource->starting_amount_minor;
        }

        $increment = (int) ($snapshot->minimum_bid_increment_minor ?? $this->resource->minimum_bid_increment_minor);

        return $currentAmountMinor + $increment;
    }

    private function reserveMet(int $currentAmountMinor): bool
    {
        $reserve = $this->resource->reserve_amount_minor;

        return $reserve === null || $currentAmountMinor >= (int) $reserve;
    }

    private function timeline(?AuctionSettlement $settlement): array
    {
        return [
            'published_at' => $this->resource->published_at?->toIso8601String(),
            'starts_at' => $this->resource->starts_at?->toIso8601String(),
            'original_ends_at' => $this->resource->original_ends_at?->toIso8601String(),
            'ends_at' => $this->resource->ends_at?->toIso8601String(),
            'started_at' => $this->resource->started_at?->toIso8601String(),
            'ended_at' => $this->resource->ended_at?->toIso8601String(),
            'finalized_at' => $this->resource->finalized_at?->toIso8601String(),
            'completed_at' => $this->resource->completed_at?->toIso8601String(),
            'cancelled_at' => $this->resource->cancelled_at?->toIso8601String(),
            'registration_deadline' => $this->resource->ends_at?->toIso8601String(),
            'bidder_deposit_deadline' => $this->resource->ends_at?->toIso8601String(),
            'seller_deposit_due_at' => $this->resource->seller_deposit_due_at?->toIso8601String(),
            'winner_payment_due_at' => $settlement?->payment_due_at?->toIso8601String(),
            'winner_payment_grace_ends_at' => $this->graceEndsAt($settlement)?->toIso8601String(),
            'handover_due_at' => $settlement?->handover_due_at?->toIso8601String(),
            'extension' => [
                'count' => (int) $this->resource->extension_count,
                'last_extended_at' => $this->resource->last_extended_at?->toIso8601String(),
                'window_seconds' => (int) $this->resource->extension_window_seconds,
                'duration_seconds' => (int) $this->resource->extension_duration_seconds,
                'maximum' => (int) $this->resource->maximum_extension_count,
            ],
        ];
    }

    private function graceEndsAt(?AuctionSettlement $settlement): ?CarbonInterface
    {
        if ($settlement?->payment_due_at === null) {
            return null;
        }

        if ($settlement->payment_grace_ends_at !== null) {
            return $settlement->payment_grace_ends_at;
        }

        $snapshot = $this->resource->relationLoaded('configurationSnapshot')
            ? $this->resource->configurationSnapshot
            : null;

        $graceMinutes = (int) ($snapshot->winner_payment_grace_period_minutes
            ?? config('auction.deadlines.winner_payment_grace_period_hours', 24) * 60);

        return $settlement->payment_due_at->addMinutes($graceMinutes);
    }

    private function metrics(): array
    {
        $metric = $this->resource->relationLoaded('metric') ? $this->resource->metric : null;

        return [
            'views_count' => (int) ($metric->views_count ?? 0),
            'unique_views_count' => (int) ($metric->unique_views_count ?? 0),
            'participants_count' => (int) ($metric->participants_count ?? 0),
            'bids_count' => (int) ($metric->bids_count ?? 0),
            'unique_bidders_count' => (int) ($metric->unique_bidders_count ?? 0),
            'extensions_count' => (int) ($metric->extensions_count ?? 0),
            'last_bid_at' => $metric?->last_bid_at?->toIso8601String(),
        ];
    }

    private function participationRequirements(?AuctionConfigurationSnapshot $snapshot): array
    {
        $currency = (string) $this->resource->currency_code;
        $bidderDeposit = (int) ($snapshot->bidder_deposit_required_minor ?? $this->resource->bidder_deposit_amount_minor);
        $sellerDeposit = (int) ($snapshot->seller_deposit_required_minor ?? $this->resource->seller_deposit_amount_minor);
        $increment = (int) ($snapshot->minimum_bid_increment_minor ?? $this->resource->minimum_bid_increment_minor);
        $paymentMinutes = (int) ($snapshot->winner_payment_deadline_minutes ?? ((int) $this->resource->winner_payment_deadline_hours * 60));
        $handoverMinutes = (int) ($snapshot->handover_deadline_minutes ?? ((int) $this->resource->handover_deadline_hours * 60));

        return [
            'registration_deadline' => $this->resource->ends_at?->toIso8601String(),
            'deposit_deadline' => $this->resource->ends_at?->toIso8601String(),
            'bidder_deposit_required' => MoneyResource::make($bidderDeposit, $currency),
            'seller_deposit_required' => MoneyResource::make($sellerDeposit, $currency),
            'minimum_bid_increment' => MoneyResource::make($increment, $currency),
            'winner_payment_deadline_hours' => intdiv($paymentMinutes, 60),
            'winner_payment_grace_period_hours' => (int) config('auction.deadlines.winner_payment_grace_period_hours', 24),
            'handover_deadline_hours' => intdiv($handoverMinutes, 60),
            'review_sla_hours' => (int) config('auction.deadlines.review_sla_hours', 24),
            'auto_extend' => [
                'enabled' => (bool) ($snapshot->auto_extend_enabled ?? ((int) $this->resource->extension_window_seconds > 0)),
                'window_seconds' => (int) ($snapshot->auto_extend_window_seconds ?? $this->resource->extension_window_seconds),
                'duration_seconds' => (int) ($snapshot->auto_extend_duration_seconds ?? $this->resource->extension_duration_seconds),
                'maximum_extensions' => (int) ($snapshot->maximum_extensions ?? $this->resource->maximum_extension_count),
            ],
        ];
    }

    private function winnerSettlement(?AuctionSettlement $settlement): ?array
    {
        if ($settlement === null) {
            return null;
        }

        $currency = (string) $settlement->currency_code;

        return [
            'status' => $settlement->status->value,
            'winning_amount' => MoneyResource::make((int) $settlement->winning_amount_minor, $currency),
            'deposit_applied' => MoneyResource::make((int) $settlement->deposit_applied_minor, $currency),
            'amount_due' => MoneyResource::make((int) $settlement->amount_due_minor, $currency),
            'amount_paid' => MoneyResource::make((int) $settlement->amount_paid_minor, $currency),
            'remaining_amount' => MoneyResource::make((int) $settlement->remaining_amount_minor, $currency),
            'payment_due_at' => $settlement->payment_due_at?->toIso8601String(),
            'is_overdue' => $settlement->payment_due_at !== null
                && (int) $settlement->remaining_amount_minor > 0
                && $settlement->payment_due_at->isPast(),
        ];
    }

    private function sellerContext(?AuctionSettlement $settlement, Request $request): ?array
    {
        $sellerDeposit = $this->resource->relationLoaded('sellerDeposit') ? $this->resource->sellerDeposit : null;
        $currency = (string) $this->resource->currency_code;

        return [
            'seller_deposit' => $sellerDeposit === null ? null : (new AuctionDepositResource($sellerDeposit))->toArray($request),
            'seller_payment_submissions' => $sellerDeposit === null
                ? []
                : $this->stableCollection($this->paymentSubmissionsFrom(collect([$sellerDeposit])), PaymentSubmissionResource::class, $request),
            'financial_summary' => $settlement === null ? null : [
                'status' => $settlement->status->value,
                'winning_amount' => MoneyResource::make((int) $settlement->winning_amount_minor, $currency),
                'platform_fee' => MoneyResource::make((int) $settlement->platform_fee_minor, $currency),
                'seller_net_amount' => MoneyResource::make((int) $settlement->seller_net_amount_minor, $currency),
                'completed_at' => $settlement->completed_at?->toIso8601String(),
                'payout' => $settlement->relationLoaded('sellerPayout') && $settlement->sellerPayout
                    ? SellerPayoutResource::sellerPayload($settlement->sellerPayout)
                    : null,
            ],
        ];
    }

    private function handoverStatus(?AuctionSettlement $settlement): ?array
    {
        if ($settlement === null) {
            return null;
        }

        return [
            'payment_due_at' => $settlement->payment_due_at?->toIso8601String(),
            'handover_due_at' => $settlement->handover_due_at?->toIso8601String(),
            'is_handover_overdue' => $settlement->handover_due_at !== null
                && $settlement->handover_completed_at === null
                && $settlement->handover_due_at->isPast(),
            'seller_handover_confirmed_at' => $settlement->seller_handover_confirmed_at?->toIso8601String(),
            'buyer_receipt_confirmed_at' => $settlement->buyer_receipt_confirmed_at?->toIso8601String(),
            'handover_completed_at' => $settlement->handover_completed_at?->toIso8601String(),
        ];
    }

    private function currentDispute(bool $isSeller, bool $isWinner): ?array
    {
        if (! $isSeller && ! $isWinner) {
            return null;
        }

        if (! $this->resource->relationLoaded('disputes')) {
            return null;
        }

        $dispute = $this->resource->disputes->firstWhere('status', 'open');

        if ($dispute === null) {
            return null;
        }

        return [
            'id' => $dispute->public_id,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
            'opened_at' => $dispute->opened_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
        ];
    }

    private function paymentSubmissionsFrom(Collection $deposits): Collection
    {
        return $deposits
            ->filter(fn (AuctionDeposit $deposit): bool => $deposit->relationLoaded('paymentSubmissions'))
            ->flatMap(fn (AuctionDeposit $deposit): Collection => $deposit->paymentSubmissions)
            ->values();
    }

    private function refundsFrom(Collection $deposits): array
    {
        return $this->paymentSubmissionsFrom($deposits)
            ->filter(fn ($submission): bool => $submission->relationLoaded('transaction') && $submission->transaction?->relationLoaded('refunds'))
            ->flatMap(fn ($submission): Collection => $submission->transaction->refunds)
            ->map(fn ($refund): array => [
                'id' => $refund->public_id,
                'status' => $refund->status->value,
                'amount' => MoneyResource::make((int) $refund->amount_minor, $refund->currency_code),
                'reason' => $refund->reason,
                'attempt_count' => (int) $refund->attempt_count,
                'processed_at' => $refund->processed_at?->toIso8601String(),
                'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
                'failed_at' => $refund->failed_at?->toIso8601String(),
                'cancelled_at' => $refund->cancelled_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

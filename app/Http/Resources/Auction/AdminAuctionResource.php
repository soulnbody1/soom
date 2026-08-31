<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Http\Resources\ContentReview\ContentReviewResource;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Services\ContentReview\Support\ContentReviewActionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class AdminAuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReviewPayments = $user && Gate::forUser($user)->allows('viewAny', PaymentSubmission::class);
        $canManageSettlement = $user
            && Gate::forUser($user)->allows('override', $this->settlement ?? new AuctionSettlement);
        $canResolveDisputes = $user && Gate::forUser($user)->allows('resolveDispute', $this->resource);

        $data = [
            'id' => $this->public_id,
            'internal_id' => $this->id,
            'seller_id' => $this->seller_id,
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
                'phone' => $this->seller->phone,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => __('auction.statuses.'.$this->status->value),
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
            'deadlines' => $this->deadlines(),
            'operational_flags' => $this->operationalFlags(),
            'next_admin_action' => $this->nextAdminAction(),
            'configuration_snapshot' => $this->configurationSnapshotPayload(),
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
            $data['refunds'] = $this->refundRows();
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

            if ($this->settlement && $this->settlement->relationLoaded('sellerPayout') && $this->settlement->sellerPayout) {
                $payout = $this->settlement->sellerPayout;
                $data['financial_details']['seller_payout'] = [
                    'id' => $payout->public_id,
                    'status' => $payout->status->value,
                    'amount' => MoneyResource::make((int) $payout->amount_minor, (string) $payout->currency_code),
                    'has_destination' => $payout->hasDestinationSnapshot(),
                    'transfer_reference' => $payout->transfer_reference,
                    'paid_at' => $payout->paid_at?->toIso8601String(),
                ];
            }
        }

        $data['ai_review'] = $this->aiReviewBlock($request);

        if ($canResolveDisputes) {
            $data['disputes'] = $this->whenLoaded('disputes', fn () => $this->disputes->map(fn ($dispute): array => [
                'id' => $dispute->public_id,
                'status' => $dispute->status,
                'reason' => $dispute->reason,
                'opened_by' => $dispute->opened_by,
                'opened_at' => $dispute->opened_at?->toIso8601String(),
                'resolution_note' => $dispute->resolution_note,
                'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            ])->values());
        }

        return $data;
    }

    private function aiReviewBlock(Request $request): array
    {
        $review = $this->relationLoaded('activeContentReview') ? $this->activeContentReview : null;
        $mode = ContentReviewResource::resolvedMode($request, ReviewableSubjectType::Auction);

        return [
            'enabled' => config('content_review.enabled') === true,
            'mode' => $mode->value,
            'mode_label' => __('content_review.modes.'.$mode->value),
            'current' => $review === null ? null : (new ContentReviewResource($review))->toArray($request),
            'available_actions' => app(ContentReviewActionResolver::class)->for(
                $review,
                $mode,
                ReviewableSubjectType::Auction,
                (int) $this->id
            ),
        ];
    }

    private function awaitsAiReview(): bool
    {
        if (! $this->relationLoaded('activeContentReview')) {
            return false;
        }

        $review = $this->activeContentReview;

        return $review !== null && $review->status->isPending();
    }

    private function settlementRecord(): ?AuctionSettlement
    {
        return $this->relationLoaded('settlement') ? $this->settlement : null;
    }

    private function snapshotRecord(): ?AuctionConfigurationSnapshot
    {
        return $this->relationLoaded('configurationSnapshot') ? $this->configurationSnapshot : null;
    }

    private function deadlines(): array
    {
        $settlement = $this->settlementRecord();
        $snapshot = $this->snapshotRecord();

        return [
            'seller_deposit_due_at' => $this->seller_deposit_due_at?->toIso8601String(),
            'seller_deposit_deadline_processed_at' => $this->seller_deposit_deadline_processed_at?->toIso8601String(),
            'winner_payment_due_at' => $settlement?->payment_due_at?->toIso8601String(),
            'winner_payment_grace_ends_at' => $settlement?->payment_grace_ends_at?->toIso8601String(),
            'handover_due_at' => $settlement?->handover_due_at?->toIso8601String(),
            'winner_payment_reminder_hours' => $snapshot?->winnerPaymentReminderHours() ?? [],
            'handover_reminder_hours' => $snapshot?->handoverReminderHours() ?? [],
            'review_sla_minutes' => $snapshot?->review_sla_minutes,
        ];
    }

    private function operationalFlags(): array
    {
        $settlement = $this->settlementRecord();
        $now = Carbon::now();

        return [
            'is_seller_deposit_overdue' => $this->status === AuctionStatus::AwaitingSellerDeposit
                && $this->seller_deposit_due_at !== null
                && $now->greaterThan($this->seller_deposit_due_at),
            'is_payment_overdue' => $settlement !== null
                && $settlement->status === SettlementStatus::PaymentPending
                && $settlement->payment_due_at !== null
                && $now->greaterThan($settlement->payment_due_at),
            'is_payment_grace_expired' => $settlement !== null
                && $settlement->status === SettlementStatus::PaymentPending
                && $settlement->payment_grace_ends_at !== null
                && $now->greaterThan($settlement->payment_grace_ends_at),
            'is_handover_overdue' => $settlement !== null
                && $settlement->handover_completed_at === null
                && $settlement->handover_due_at !== null
                && $now->greaterThan($settlement->handover_due_at),
            'is_winner_payment_settled' => $settlement !== null && $settlement->isWinnerPaymentSettled(),
            'has_open_dispute' => $this->relationLoaded('disputes')
                ? $this->disputes->contains(fn ($dispute): bool => $dispute->resolved_at === null)
                : null,
        ];
    }

    private function nextAdminAction(): ?string
    {
        $flags = $this->operationalFlags();
        $settlement = $this->settlementRecord();

        return match (true) {
            $flags['has_open_dispute'] === true => 'resolve_dispute',
            $this->status === AuctionStatus::PendingReview && $this->awaitsAiReview() => 'awaiting_ai_review',
            $this->status === AuctionStatus::PendingReview => 'review_auction',
            $flags['is_seller_deposit_overdue'] === true => 'cancel_unfunded_auction',
            $flags['is_payment_grace_expired'] === true => 'mark_winner_defaulted',
            $flags['is_handover_overdue'] === true => 'follow_up_handover',
            $settlement?->status === SettlementStatus::Completed => 'release_seller_payout',
            default => null,
        };
    }

    private function configurationSnapshotPayload(): ?array
    {
        $snapshot = $this->snapshotRecord();

        if (! $snapshot) {
            return null;
        }

        return [
            'source_configuration_version_id' => $snapshot->source_configuration_version_id,
            'terms_version_id' => $snapshot->terms_version_id,
            'snapshot_hash' => $snapshot->snapshot_hash,
            'finalized_at' => $snapshot->finalized_at?->toIso8601String(),
            'currency_code' => $snapshot->currency_code,
            'minimum_bid_increment' => MoneyResource::make((int) $snapshot->minimum_bid_increment_minor, (string) $snapshot->currency_code),
            'seller_deposit_required' => MoneyResource::make((int) $snapshot->seller_deposit_required_minor, (string) $snapshot->currency_code),
            'bidder_deposit_required' => MoneyResource::make((int) $snapshot->bidder_deposit_required_minor, (string) $snapshot->currency_code),
            'auto_extend_enabled' => (bool) $snapshot->auto_extend_enabled,
            'auto_extend_window_seconds' => (int) $snapshot->auto_extend_window_seconds,
            'auto_extend_duration_seconds' => (int) $snapshot->auto_extend_duration_seconds,
            'maximum_extensions' => (int) $snapshot->maximum_extensions,
            'winner_payment_deadline_minutes' => (int) $snapshot->winner_payment_deadline_minutes,
            'winner_payment_grace_period_minutes' => $snapshot->winnerPaymentGracePeriodMinutes(),
            'seller_deposit_deadline_minutes' => $snapshot->sellerDepositDeadlineMinutes(),
            'handover_deadline_minutes' => (int) $snapshot->handover_deadline_minutes,
            'review_sla_minutes' => $snapshot->review_sla_minutes,
            'non_winner_deposit_hold_policy' => $snapshot->non_winner_deposit_hold_policy,
            'alternative_candidate_limit' => (int) $snapshot->alternative_candidate_limit,
            'alternative_winner_enabled' => (bool) $snapshot->alternative_winner_enabled,
            'winner_default_deposit_policy' => (array) $snapshot->winner_default_deposit_policy,
            'seller_deposit_policy' => (array) $snapshot->seller_deposit_policy,
            'platform_fee' => [
                'type' => $snapshot->platform_fee_type,
                'value' => (int) $snapshot->platform_fee_value,
                'min' => MoneyResource::make((int) $snapshot->platform_fee_min_minor, (string) $snapshot->currency_code),
                'max' => $snapshot->platform_fee_max_minor !== null
                    ? MoneyResource::make((int) $snapshot->platform_fee_max_minor, (string) $snapshot->currency_code)
                    : null,
            ],
        ];
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

    private function refundRows(): array
    {
        if (! $this->relationLoaded('refunds')) {
            return [];
        }

        return $this->refunds
            ->sortByDesc('id')
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

<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentChannel;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Support\AuctionDepositBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class AuctionDepositResource extends JsonResource
{
    private const PUBLIC_METADATA_KEYS = ['candidate_rank', 'policy', 'release_trigger'];

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canReviewPayments = $user && Gate::forUser($user)->allows('viewAny', PaymentSubmission::class);
        $metadata = $this->publicMetadata();
        $balance = AuctionDepositBalance::for($this->resource);

        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'type' => $this->type,
            'status' => $this->status->value,
            'required_amount' => MoneyResource::make((int) $this->required_amount_minor, $this->currency_code),
            'held_amount' => MoneyResource::make((int) $this->held_amount_minor, $this->currency_code),
            'applied_amount' => MoneyResource::make((int) $this->applied_amount_minor, $this->currency_code),
            'refunded_amount' => MoneyResource::make((int) $this->refunded_amount_minor, $this->currency_code),
            'forfeited_amount' => MoneyResource::make((int) $this->forfeited_amount_minor, $this->currency_code),
            'pending_refund_amount' => MoneyResource::make($balance->pendingRefundMinor, $this->currency_code),
            'refundable_amount' => MoneyResource::make($balance->refundableMinor, $this->currency_code),
            'hold_reason' => $this->hold_reason,
            'hold_reason_label' => $this->holdReasonLabel(),
            'candidate_rank' => isset($metadata['candidate_rank']) ? (int) $metadata['candidate_rank'] : null,
            'hold_metadata' => $metadata,
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'held_at' => $this->held_at?->toIso8601String(),
            'payment' => $this->when($canReviewPayments, fn () => $this->paymentPayload()),
            'refunds' => $this->when($canReviewPayments, fn () => $this->refundsPayload()),
            'refund_eligibility' => $this->refundEligibility(),
            'expected_release_condition' => $this->expectedReleaseCondition(),
            'user' => $this->when(
                $canReviewPayments && $this->relationLoaded('user') && $this->user,
                fn () => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ]
            ),
        ];
    }

    private function paymentPayload(): ?array
    {
        $payment = $this->relationLoaded('paymentTransaction') ? $this->paymentTransaction : null;

        if ($payment === null) {
            return null;
        }

        $method = $payment->relationLoaded('paymentMethod') ? $payment->paymentMethod : null;

        if ($method === null && $payment->relationLoaded('submission')) {
            $method = $payment->submission?->paymentMethod;
        }

        return [
            'id' => $payment->public_id,
            'channel' => $payment->isOnline() ? PaymentChannel::Online->value : PaymentChannel::Manual->value,
            'status' => $payment->status->value,
            'provider' => $payment->provider,
            'provider_transaction_id' => $payment->provider_transaction_id,
            'paid_amount' => MoneyResource::make((int) $payment->amount_minor, (string) $payment->currency_code),
            'paid_at' => $payment->processed_at?->toIso8601String(),
            'payment_method' => $method === null ? null : [
                'id' => $method->public_id,
                'name' => $method->name,
            ],
        ];
    }

    private function refundsPayload(): array
    {
        if (! $this->relationLoaded('refunds')) {
            return [];
        }

        return $this->refunds
            ->map(fn (RefundTransaction $refund): array => [
                'id' => $refund->public_id,
                'status' => $refund->status->value,
                'amount' => MoneyResource::make((int) $refund->amount_minor, (string) $refund->currency_code),
                'provider' => $refund->provider,
                'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function publicMetadata(): array
    {
        $metadata = is_array($this->hold_metadata) ? $this->hold_metadata : [];

        return array_intersect_key($metadata, array_flip(self::PUBLIC_METADATA_KEYS));
    }

    private function holdReasonLabel(): ?string
    {
        if ($this->hold_reason === null) {
            return null;
        }

        $key = 'auction.deposit_hold_reasons.'.$this->hold_reason;
        $label = __($key);

        return $label === $key ? null : $label;
    }

    private function refundEligibility(): string
    {
        return match ($this->status) {
            AuctionDepositStatus::Refunded => 'refunded',
            AuctionDepositStatus::RefundPending => 'scheduled',
            AuctionDepositStatus::Forfeited => 'forfeited',
            AuctionDepositStatus::AppliedToSettlement => 'applied_to_settlement',
            AuctionDepositStatus::Held => $this->hold_reason === null ? 'pending_auction_result' : 'on_hold',
            default => 'not_yet_held',
        };
    }

    private function expectedReleaseCondition(): ?string
    {
        if ($this->status !== AuctionDepositStatus::Held) {
            return null;
        }

        return match ($this->hold_reason) {
            'alternative_winner_candidate' => 'winner_payment_confirmed',
            'seller_deposit_manual_review' => 'admin_review',
            'seller_deposit_keep_held' => 'process_completion',
            null => 'auction_result',
            default => 'admin_review',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Models\Auction\PaymentSubmission;
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
            'hold_reason' => $this->hold_reason,
            'hold_reason_label' => $this->holdReasonLabel(),
            'candidate_rank' => isset($metadata['candidate_rank']) ? (int) $metadata['candidate_rank'] : null,
            'hold_metadata' => $metadata,
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
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

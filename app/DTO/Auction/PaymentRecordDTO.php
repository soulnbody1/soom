<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentRecordStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Http\Resources\Auction\MoneyResource;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Support\AuctionDepositBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PaymentRecordDTO
{
    public function __construct(
        private readonly PaymentTransaction|PaymentSubmission $record,
        private readonly ?AuctionDeposit $deposit = null,
    ) {}

    public static function from(Model $record, ?AuctionDeposit $deposit = null): self
    {
        return new self($record, $deposit);
    }

    public function toArray(): array
    {
        $transaction = $this->transaction();
        $submission = $this->submission();

        return [
            'id' => $this->record->public_id,
            'source' => $transaction ? 'transaction' : 'submission',
            'channel' => $this->channel()->value,
            'status' => $this->status()->value,
            'purpose' => $this->record->purpose->value,
            'amount' => MoneyResource::make((int) $this->record->amount_minor, (string) $this->record->currency_code),
            'provider' => $transaction?->provider,
            'provider_transaction_id' => $transaction?->provider_transaction_id,
            'payment_method' => $this->paymentMethod(),
            'auction' => $this->auction(),
            'user' => $this->user(),
            'has_receipt' => $submission !== null,
            'submission_id' => $submission?->public_id,
            'created_at' => $this->record->created_at?->toIso8601String(),
            'succeeded_at' => $transaction?->processed_at?->toIso8601String(),
            'refund' => $this->refundSummary(),
        ];
    }

    public function detail(): array
    {
        $transaction = $this->transaction();

        return $this->toArray() + [
            'captured_amount' => $transaction?->captured_amount_minor === null
                ? null
                : MoneyResource::make(
                    (int) $transaction->captured_amount_minor,
                    (string) ($transaction->captured_currency_code ?: $transaction->currency_code)
                ),
            'failure_code' => $transaction?->failure_code,
            'settlement_reference' => $transaction?->settlement_reference,
            'settled_at' => $transaction?->settled_at?->toIso8601String(),
            'expires_at' => $transaction?->expires_at?->toIso8601String(),
            'deposit' => $this->deposit(),
            'review' => $this->review(),
            'refunds' => $this->refunds()->map(fn (RefundTransaction $refund): array => [
                'id' => $refund->public_id,
                'status' => $refund->status->value,
                'amount' => MoneyResource::make((int) $refund->amount_minor, (string) $refund->currency_code),
                'provider' => $refund->provider,
                'provider_refund_id' => $refund->provider_refund_id,
                'reason' => $refund->reason,
                'attempt_count' => (int) $refund->attempt_count,
                'last_error' => $refund->last_error,
                'created_at' => $refund->created_at?->toIso8601String(),
                'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function transaction(): ?PaymentTransaction
    {
        return $this->record instanceof PaymentTransaction ? $this->record : null;
    }

    private function submission(): ?PaymentSubmission
    {
        if ($this->record instanceof PaymentSubmission) {
            return $this->record;
        }

        return $this->record->relationLoaded('submission') ? $this->record->submission : null;
    }

    private function channel(): PaymentChannel
    {
        $transaction = $this->transaction();

        return $transaction && $transaction->isOnline() ? PaymentChannel::Online : PaymentChannel::Manual;
    }

    private function status(): PaymentRecordStatus
    {
        $transaction = $this->transaction();

        return $transaction
            ? PaymentRecordStatus::fromTransaction($transaction->status)
            : PaymentRecordStatus::fromSubmission($this->record->status);
    }

    private function paymentMethod(): ?array
    {
        $method = $this->record->relationLoaded('paymentMethod') ? $this->record->paymentMethod : null;
        $submission = $this->submission();

        if ($method === null && $submission?->relationLoaded('paymentMethod')) {
            $method = $submission->paymentMethod;
        }

        return $method === null ? null : [
            'id' => $method->public_id,
            'name' => $method->name,
            'channel' => $method->channel,
            'provider_code' => $method->provider_code,
        ];
    }

    private function auction(): ?array
    {
        $auction = $this->record->relationLoaded('auction') ? $this->record->auction : null;

        return $auction === null ? null : [
            'id' => $auction->public_id,
            'title' => $auction->title,
        ];
    }

    private function user(): ?array
    {
        $user = $this->record->relationLoaded('user') ? $this->record->user : null;

        return $user === null ? null : [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    private function deposit(): ?array
    {
        $submission = $this->submission();
        $deposit = $this->deposit
            ?? ($submission?->relationLoaded('deposit') ? $submission->deposit : null);

        if ($deposit === null) {
            return null;
        }

        $balance = AuctionDepositBalance::for($deposit);
        $currency = (string) $deposit->currency_code;

        return [
            'id' => $deposit->public_id,
            'type' => $deposit->type,
            'status' => $deposit->status->value,
            'required_amount' => MoneyResource::make($balance->requiredMinor, $currency),
            'held_amount' => MoneyResource::make($balance->heldMinor, $currency),
            'applied_amount' => MoneyResource::make($balance->appliedMinor, $currency),
            'refunded_amount' => MoneyResource::make($balance->refundedMinor, $currency),
            'forfeited_amount' => MoneyResource::make($balance->forfeitedMinor, $currency),
            'pending_refund_amount' => MoneyResource::make($balance->pendingRefundMinor, $currency),
            'refundable_amount' => MoneyResource::make($balance->refundableMinor, $currency),
            'held_at' => $deposit->held_at?->toIso8601String(),
        ];
    }

    private function review(): ?array
    {
        $submission = $this->submission();

        return $submission === null ? null : [
            'status' => $submission->status->value,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'note' => $submission->review_note,
        ];
    }

    private function refunds(): Collection
    {
        $transaction = $this->transaction();

        if ($transaction === null || ! $transaction->relationLoaded('refunds')) {
            return collect();
        }

        return $transaction->refunds;
    }

    private function refundSummary(): ?array
    {
        $refunds = $this->refunds();

        if ($refunds->isEmpty()) {
            return null;
        }

        $succeeded = $refunds->where('status', RefundTransactionStatus::Succeeded);
        $currency = (string) $this->record->currency_code;

        return [
            'count' => $refunds->count(),
            'refunded_amount' => MoneyResource::make((int) $succeeded->sum('amount_minor'), $currency),
            'pending_count' => $refunds->count() - $succeeded->count(),
            'latest_status' => $refunds->sortByDesc('id')->first()?->status->value,
        ];
    }
}

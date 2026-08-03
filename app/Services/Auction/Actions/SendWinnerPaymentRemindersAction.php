<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionSettlement;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\ReminderSchedule;
use Illuminate\Support\Carbon;

final class SendWinnerPaymentRemindersAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function execute(AuctionSettlement $settlement): array
    {
        return $this->transaction->run(function () use ($settlement): array {
            $settlement = $this->settlements->lockById($settlement->id);
            $auction = Auction::whereKey($settlement->auction_id)->first();

            if (! $auction || $auction->status !== AuctionStatus::PaymentPending) {
                return [];
            }

            if ($settlement->status !== SettlementStatus::PaymentPending || ! $settlement->is_current) {
                return [];
            }

            if ($settlement->payment_due_at === null) {
                return [];
            }

            $now = Carbon::now();
            $snapshot = $this->snapshotReader->forAuction($auction);
            $graceEndsAt = $settlement->payment_grace_ends_at
                ?? $settlement->payment_due_at->addMinutes($snapshot->winnerPaymentGracePeriodMinutes());

            if ($now->greaterThanOrEqualTo($graceEndsAt)) {
                return [];
            }

            $alreadySent = ReminderSchedule::normalizeSent($settlement->payment_reminders_sent);

            $outcome = ReminderSchedule::resolve(
                $snapshot->winnerPaymentReminderHours(),
                $settlement->payment_due_at,
                $now,
                $alreadySent
            );

            if ($outcome['record'] === []) {
                return [];
            }

            if ($outcome['send'] !== null) {
                $this->audit->outbox('auction.winner_payment_reminder', $auction, [
                    'auction_public_id' => $auction->public_id,
                    'settlement_public_id' => $settlement->public_id,
                    'winner_id' => (int) $settlement->winner_id,
                    'hours_before' => $outcome['send'],
                    'payment_due_at' => $settlement->payment_due_at->toIso8601String(),
                    'payment_grace_ends_at' => $graceEndsAt->toIso8601String(),
                    'remaining_amount_minor' => (int) $settlement->remaining_amount_minor,
                    'currency_code' => (string) $settlement->currency_code,
                ]);
            }

            $settlement->forceFill([
                'payment_reminders_sent' => ReminderSchedule::merge($alreadySent, $outcome['record']),
            ]);
            $this->settlements->save($settlement);

            return $outcome['send'] === null ? [] : [$outcome['send']];
        });
    }
}

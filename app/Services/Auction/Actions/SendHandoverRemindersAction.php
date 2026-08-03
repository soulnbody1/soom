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

final class SendHandoverRemindersAction
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

            if (! $auction || $auction->status !== AuctionStatus::HandoverPending) {
                return [];
            }

            if (! in_array($settlement->status, [SettlementStatus::Paid, SettlementStatus::HandoverPending], true) || ! $settlement->is_current) {
                return [];
            }

            if ($settlement->handover_due_at === null || $settlement->handover_completed_at !== null) {
                return [];
            }

            $audience = $settlement->seller_handover_confirmed_at === null ? 'seller' : 'winner';

            if ($audience === 'winner' && $settlement->buyer_receipt_confirmed_at !== null) {
                return [];
            }

            $snapshot = $this->snapshotReader->forAuction($auction);
            $alreadySent = ReminderSchedule::normalizeSent($this->sentFor($settlement, $audience));

            $outcome = ReminderSchedule::resolve(
                $snapshot->handoverReminderHours(),
                $settlement->handover_due_at,
                Carbon::now(),
                $alreadySent
            );

            if ($outcome['record'] === []) {
                return [];
            }

            if ($outcome['send'] !== null) {
                $this->audit->outbox('auction.handover_reminder', $auction, [
                    'auction_public_id' => $auction->public_id,
                    'settlement_public_id' => $settlement->public_id,
                    'audience' => $audience,
                    'hours_before' => $outcome['send'],
                    'handover_due_at' => $settlement->handover_due_at->toIso8601String(),
                ]);
            }

            $tracker = (array) ($settlement->handover_reminders_sent ?? []);
            $tracker[$audience] = ReminderSchedule::merge($alreadySent, $outcome['record']);

            $settlement->forceFill(['handover_reminders_sent' => $tracker]);
            $this->settlements->save($settlement);

            return $outcome['send'] === null ? [] : [$outcome['send']];
        });
    }

    private function sentFor(AuctionSettlement $settlement, string $audience): array
    {
        return (array) (($settlement->handover_reminders_sent ?? [])[$audience] ?? []);
    }
}

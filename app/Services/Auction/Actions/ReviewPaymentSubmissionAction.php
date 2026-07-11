<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentSubmission;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ReviewPaymentSubmissionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionSettlementRepository $settlements,
    ) {}

    public function approve(PaymentSubmission $submission, int $adminId, string $note = ''): PaymentSubmission
    {
        return $this->transaction->run(function () use ($submission, $adminId, $note): PaymentSubmission {
            $submission = $this->payments->lockSubmissionForReview($submission->id);

            if ($submission->status !== PaymentSubmissionStatus::PendingReview) {
                return $submission->load(['auction', 'deposit', 'settlement', 'transaction']);
            }

            $auction = $this->payments->lockSubmissionAuction($submission);
            $submission->forceFill([
                'status' => PaymentSubmissionStatus::Approved,
                'reviewed_by' => $adminId,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);
            $this->payments->save($submission);

            $this->payments->firstOrCreateTransaction(
                [
                    'purpose' => $submission->purpose->value,
                    'idempotency_key' => "submission:{$submission->id}:approved",
                ],
                [
                    'payment_submission_id' => $submission->id,
                    'auction_id' => $auction->id,
                    'user_id' => $submission->user_id,
                    'status' => PaymentTransactionStatus::Succeeded,
                    'amount_minor' => $submission->amount_minor,
                    'currency_code' => $submission->currency_code,
                    'provider' => 'manual',
                    'provider_transaction_id' => $submission->provider_reference,
                    'processed_at' => Carbon::now(),
                ]
            );

            if ($submission->purpose === PaymentPurpose::SellerDeposit) {
                $deposit = $this->payments->lockSubmissionDeposit($submission);
                $deposit->forceFill([
                    'status' => AuctionDepositStatus::Held,
                    'held_amount_minor' => $submission->amount_minor,
                    'held_at' => Carbon::now(),
                ])->save();

                $this->stateMachine->transition($auction, AuctionStatus::Scheduled, $adminId, 'admin', 'seller deposit approved');
            }

            if ($submission->purpose === PaymentPurpose::BidderDeposit) {
                $deposit = $this->payments->lockSubmissionDeposit($submission);
                $deposit->forceFill([
                    'status' => AuctionDepositStatus::Held,
                    'held_amount_minor' => $submission->amount_minor,
                    'held_at' => Carbon::now(),
                ])->save();

                $deposit->participant?->forceFill([
                    'status' => AuctionParticipantStatus::Qualified,
                    'qualified_at' => Carbon::now(),
                ])->save();
            }

            if ($submission->purpose === PaymentPurpose::WinnerSettlement) {
                $settlement = $this->payments->lockSubmissionSettlement($submission);

                // Verify winner hasn't changed since submission
                if ($settlement->winner_id !== $submission->user_id) {
                    throw new AuctionException(__('auction.errors.winner_changed'));
                }

                // Verify settlement isn't already fully paid
                if ($settlement->status === SettlementStatus::Paid) {
                    throw new AuctionException(__('auction.errors.payment_already_processed'));
                }

                $newPaid = min($settlement->amount_due_minor, $settlement->amount_paid_minor + $submission->amount_minor);
                $settlement->forceFill([
                    'amount_paid_minor' => $newPaid,
                    'status' => $newPaid >= $settlement->amount_due_minor ? SettlementStatus::Paid : SettlementStatus::PaymentPending,
                    'paid_at' => $newPaid >= $settlement->amount_due_minor ? Carbon::now() : $settlement->paid_at,
                    'handover_due_at' => $newPaid >= $settlement->amount_due_minor
                        ? Carbon::now()->addHours($auction->handover_deadline_hours)
                        : $settlement->handover_due_at,
                ]);
                $this->settlements->save($settlement);

                if ($newPaid >= $settlement->amount_due_minor) {
                    $this->stateMachine->transition($auction, AuctionStatus::HandoverPending, $adminId, 'admin', 'winner payment approved');
                }
            }

            $this->audit->log('auction.payment_approved', $auction, $adminId, 'admin', [
                'submission_public_id' => $submission->public_id,
                'purpose' => $submission->purpose->value,
            ]);

            return $submission->refresh()->load(['auction', 'deposit', 'settlement', 'transaction']);
        });
    }

    public function reject(PaymentSubmission $submission, int $adminId, string $note): PaymentSubmission
    {
        if (trim($note) === '') {
            throw AuctionException::paymentRejected(__('auction.errors.payment_rejection_reason_required'));
        }

        return $this->transaction->run(function () use ($submission, $adminId, $note): PaymentSubmission {
            $submission = $this->payments->lockSubmissionForReview($submission->id);

            if ($submission->status !== PaymentSubmissionStatus::PendingReview) {
                return $submission;
            }

            $submission->forceFill([
                'status' => PaymentSubmissionStatus::Rejected,
                'reviewed_by' => $adminId,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);
            $this->payments->save($submission);

            if ($submission->deposit) {
                $submission->deposit->forceFill([
                    'status' => AuctionDepositStatus::Rejected,
                    'released_at' => Carbon::now(),
                ])->save();
            }

            $this->audit->log('auction.payment_rejected', $submission->auction, $adminId, 'admin', [
                'submission_public_id' => $submission->public_id,
                'reason' => $note,
            ]);

            return $submission->refresh()->load(['auction', 'deposit']);
        });
    }
}

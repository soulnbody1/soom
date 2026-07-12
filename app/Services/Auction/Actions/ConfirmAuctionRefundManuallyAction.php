<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionRefundCompletion;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Facades\Gate;

final class ConfirmAuctionRefundManuallyAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionRefundCompletion $completion,
    ) {}

    public function execute(
        RefundTransaction $refund,
        User $admin,
        string $confirmationReference,
        string $reason,
        array $evidence = []
    ): RefundTransaction {
        $confirmationReference = trim($confirmationReference);
        $reason = trim($reason);

        if ($confirmationReference === '' || $reason === '') {
            throw new AuctionException(__('auction.errors.refund_manual_confirmation_reason_required'));
        }

        return $this->transaction->run(function () use ($refund, $admin, $confirmationReference, $reason, $evidence): RefundTransaction {
            $refund = $this->refunds->lockForConfirmation($refund->id);

            if ($refund->status === RefundTransactionStatus::Succeeded) {
                return $refund;
            }

            if ($refund->status === RefundTransactionStatus::Cancelled) {
                throw new AuctionException(__('auction.errors.refund_manual_confirmation_not_allowed'));
            }

            if (
                $refund->status === RefundTransactionStatus::Processing
                && $refund->lease_expires_at
                && $refund->lease_expires_at->isFuture()
            ) {
                throw new AuctionException(__('auction.errors.refund_manual_confirmation_not_allowed'));
            }

            if (! Gate::forUser($admin)->allows('confirmManual', $refund)) {
                throw new AuctionException(__('auction.errors.refund_manual_confirmation_unauthorized'));
            }

            $refund->forceFill(['provider' => 'manual']);

            $completed = $this->completion->completeSucceeded(
                $refund,
                $confirmationReference,
                providerResponse: [
                    'manual_confirmation' => [
                        'reason' => $reason,
                        'evidence' => $evidence,
                    ],
                ],
                manualConfirmedBy: $admin->id,
                manualConfirmationReason: $reason,
                actorId: $admin->id,
                actorType: 'admin',
            );

            $this->audit->log('auction.refund_manually_confirmed', $completed->auction, $admin->id, 'admin', [
                'refund_public_id' => $completed->public_id,
                'provider_refund_id' => $confirmationReference,
                'reason' => $reason,
            ]);

            return $completed;
        });
    }
}

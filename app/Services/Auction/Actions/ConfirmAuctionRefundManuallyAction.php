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
use App\Services\Auction\Support\RefundDestinationRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ConfirmAuctionRefundManuallyAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionRefundCompletion $completion,
        private readonly RefundDestinationRules $destinations,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array{recipient_name?: string|null, identifier_type?: string|null, identifier_value?: string|null}  $destinationOverride
     */
    public function execute(
        RefundTransaction $refund,
        User $admin,
        string $confirmationReference,
        string $reason,
        array $evidence = [],
        array $destinationOverride = [],
        ?UploadedFile $proof = null,
    ): RefundTransaction {
        $confirmationReference = trim($confirmationReference);
        $reason = trim($reason);

        if ($confirmationReference === '' || $reason === '') {
            throw AuctionException::domain('refund_manual_confirmation_reason_required');
        }

        $proofPath = $proof?->store("auction-refunds/{$refund->public_id}", 'spaces_private') ?: null;

        try {
            $completed = $this->transaction->run(function () use (
                $refund,
                $admin,
                $confirmationReference,
                $reason,
                $evidence,
                $destinationOverride,
                $proof,
                $proofPath
            ): RefundTransaction {
                $refund = $this->refunds->lockForConfirmation($refund->id);

                if ($refund->status === RefundTransactionStatus::Succeeded) {
                    return $refund;
                }

                if ($refund->status === RefundTransactionStatus::Cancelled) {
                    throw AuctionException::domain('refund_manual_confirmation_not_allowed');
                }

                if (
                    $refund->status === RefundTransactionStatus::Processing
                    && $refund->lease_expires_at
                    && $refund->lease_expires_at->isFuture()
                ) {
                    throw AuctionException::domain('refund_manual_confirmation_not_allowed');
                }

                if (! Gate::forUser($admin)->allows('confirmManual', $refund)) {
                    throw AuctionException::domain('refund_manual_confirmation_unauthorized');
                }

                $this->destinations->ensureDestinationSnapshot($refund, $destinationOverride);

                $refund->forceFill(['provider' => 'manual']);

                if ($proofPath !== null && $proof !== null) {
                    $refund->forceFill([
                        'proof_disk' => 'spaces_private',
                        'proof_path' => $proofPath,
                        'proof_mime_type' => (string) $proof->getMimeType(),
                        'proof_size_bytes' => (int) $proof->getSize(),
                    ]);
                }

                $completed = $this->completion->completeSucceeded(
                    $refund,
                    $confirmationReference,
                    providerResponse: [
                        'manual_confirmation' => [
                            'reason' => $reason,
                            'evidence' => $evidence + array_filter([
                                'proof_path' => $proofPath,
                                'proof_mime_type' => $proof?->getMimeType(),
                            ]),
                            'destination' => [
                                'recipient_name' => $refund->recipient_name,
                                'identifier_type' => $refund->identifier_type,
                                'identifier_value' => $refund->identifier_value,
                            ],
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
                    'identifier_type' => $completed->identifier_type,
                    'destination_id' => $completed->destination_id,
                    'has_proof' => $completed->proof_path !== null,
                ]);

                return $completed;
            });
        } catch (Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('spaces_private')->delete($proofPath);
            }

            throw $exception;
        }

        if ($proofPath !== null && $completed->proof_path !== $proofPath) {
            Storage::disk('spaces_private')->delete($proofPath);
        }

        return $completed;
    }
}

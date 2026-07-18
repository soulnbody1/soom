<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\User;
use App\Repositories\Auction\AuctionSellerPayoutRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\SellerPayoutRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MarkSellerPayoutPaidAction
{
    private const ALLOWED_FROM = [
        SellerPayoutStatus::Pending,
        SellerPayoutStatus::Processing,
    ];

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionSellerPayoutRepository $payouts,
        private readonly SellerPayoutRules $rules,
    ) {}

    /**
     * @param  array{recipient_name?: string|null, identifier_type?: string|null, identifier_value?: string|null}  $destinationOverride
     */
    public function execute(
        AuctionSellerPayout $payout,
        User $admin,
        string $payoutMethod,
        string $transferReference,
        UploadedFile $proof,
        ?string $note = null,
        array $destinationOverride = [],
    ): AuctionSellerPayout {
        $proofPath = $proof->store("auction-payouts/{$payout->public_id}", 'spaces_private');

        try {
            return $this->transaction->run(function () use ($payout, $admin, $payoutMethod, $transferReference, $proof, $proofPath, $note, $destinationOverride): AuctionSellerPayout {
                $payout = $this->payouts->lockById($payout->id);

                if ($payout->status === SellerPayoutStatus::Paid) {
                    throw new AuctionException(__('auction.errors.payout_already_paid'));
                }

                if (! in_array($payout->status, self::ALLOWED_FROM, true)) {
                    throw new AuctionException(__('auction.errors.payout_status_invalid'));
                }

                $this->rules->ensureNoOpenDispute($payout);
                $this->rules->ensureDestinationSnapshot($payout, $destinationOverride);

                $payout->forceFill([
                    'status' => SellerPayoutStatus::Paid,
                    'payout_method' => $payoutMethod,
                    'transfer_reference' => $transferReference,
                    'note' => $note,
                    'proof_disk' => 'spaces_private',
                    'proof_path' => $proofPath,
                    'proof_mime_type' => (string) $proof->getMimeType(),
                    'proof_size_bytes' => (int) $proof->getSize(),
                    'paid_at' => Carbon::now(),
                    'paid_by' => $admin->id,
                    'failure_reason' => null,
                ]);
                $this->payouts->save($payout);

                $auction = $payout->auction;
                $this->audit->log('auction.seller_payout_paid', $auction, $admin->id, 'admin', [
                    'payout_public_id' => $payout->public_id,
                    'transfer_reference' => $transferReference,
                    'payout_method' => $payoutMethod,
                    'amount_minor' => (int) $payout->amount_minor,
                ]);
                $this->audit->outbox('auction.seller_payout_paid', $auction, [
                    'auction_public_id' => $auction->public_id,
                    'payout_public_id' => $payout->public_id,
                    'amount_minor' => (int) $payout->amount_minor,
                    'currency_code' => $payout->currency_code,
                ]);

                return $payout;
            });
        } catch (Throwable $exception) {
            Storage::disk('spaces_private')->delete($proofPath);

            throw $exception;
        }
    }
}

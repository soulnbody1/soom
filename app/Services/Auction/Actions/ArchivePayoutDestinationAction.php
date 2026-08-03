<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\PayoutDestination;
use App\Services\Auction\Support\AuctionTransaction;

final class ArchivePayoutDestinationAction
{
    private const BLOCKING_STATUSES = [
        SellerPayoutStatus::Pending,
        SellerPayoutStatus::Processing,
        SellerPayoutStatus::OnHold,
        SellerPayoutStatus::ManualReview,
    ];

    public function __construct(
        private readonly AuctionTransaction $transaction,
    ) {}

    public function execute(PayoutDestination $destination, int $userId): void
    {
        $this->transaction->run(function () use ($destination, $userId): void {
            $locked = PayoutDestination::whereKey($destination->id)->lockForUpdate()->first();

            if (! $locked || (int) $locked->user_id !== $userId) {
                throw AuctionException::domain('payout_destination_not_found', [], 404);
            }

            $attached = AuctionSellerPayout::where('destination_id', $locked->id)
                ->whereIn('status', array_map(
                    static fn (SellerPayoutStatus $status): string => $status->value,
                    self::BLOCKING_STATUSES
                ))
                ->exists();

            if ($attached) {
                throw AuctionException::domain('payout_destination_in_use');
            }

            if ($locked->is_default) {
                $pendingPayouts = AuctionSellerPayout::where('seller_id', $userId)
                    ->whereIn('status', array_map(
                        static fn (SellerPayoutStatus $status): string => $status->value,
                        self::BLOCKING_STATUSES
                    ))
                    ->exists();

                $replacement = PayoutDestination::where('user_id', $userId)
                    ->whereKeyNot($locked->id)
                    ->orderByDesc('id')
                    ->first();

                if ($pendingPayouts && ! $replacement) {
                    throw AuctionException::domain('payout_destination_default_required');
                }

                $locked->forceFill(['is_default' => false, 'default_marker' => null])->save();

                if ($replacement) {
                    $replacement->forceFill(['is_default' => true, 'default_marker' => 1])->save();
                }
            }

            $locked->delete();
        });
    }
}

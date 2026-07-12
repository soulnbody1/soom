<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;

final class FinancialObligationKey
{
    public static function forDeposit(AuctionDeposit $deposit): string
    {
        return "deposit:{$deposit->id}";
    }

    public static function forSettlement(AuctionSettlement $settlement): string
    {
        return "settlement:{$settlement->id}";
    }

    public static function forSubmission(PaymentSubmission $submission): string
    {
        if (in_array($submission->purpose, [PaymentPurpose::SellerDeposit, PaymentPurpose::BidderDeposit], true) && $submission->deposit_id) {
            return "deposit:{$submission->deposit_id}";
        }

        if ($submission->purpose === PaymentPurpose::WinnerSettlement && $submission->settlement_id) {
            return "settlement:{$submission->settlement_id}";
        }

        throw new AuctionException(__('auction.errors.payment_submission_obligation_mismatch'));
    }
}

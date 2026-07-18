<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Models\User;

final class OutboxPayloadResolver
{
    public function bid(array $payload): ?AuctionBid
    {
        $publicId = (string) ($payload['bid_public_id'] ?? '');

        return $publicId !== ''
            ? AuctionBid::with('previousBid')->where('public_id', $publicId)->first()
            : null;
    }

    public function deposit(array $payload): ?AuctionDeposit
    {
        $publicId = (string) ($payload['deposit_public_id'] ?? '');

        return $publicId !== ''
            ? AuctionDeposit::where('public_id', $publicId)->first()
            : null;
    }

    public function refund(array $payload): ?RefundTransaction
    {
        $refundId = (int) ($payload['refund_transaction_id'] ?? 0);
        if ($refundId > 0) {
            return RefundTransaction::find($refundId);
        }

        $publicId = (string) ($payload['refund_public_id'] ?? '');

        return $publicId !== ''
            ? RefundTransaction::where('public_id', $publicId)->first()
            : null;
    }

    public function sellerPayout(array $payload): ?AuctionSellerPayout
    {
        $publicId = (string) ($payload['payout_public_id'] ?? '');

        return $publicId !== ''
            ? AuctionSellerPayout::where('public_id', $publicId)->first()
            : null;
    }

    public function paymentSubmissionUser(array $payload): ?User
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId > 0) {
            return User::find($userId);
        }

        $submissionId = (int) ($payload['payment_submission_id'] ?? 0);
        if ($submissionId <= 0) {
            return null;
        }

        return PaymentSubmission::with('user')->find($submissionId)?->user;
    }

    public function winner(array $payload, Auction $auction): ?User
    {
        if ($auction->settlement?->winner) {
            return $auction->settlement->winner;
        }

        $winnerId = (int) ($payload['winner_id'] ?? 0);

        return $winnerId > 0 ? User::find($winnerId) : null;
    }

    public function requiredUser(int $userId, string $eventType): User
    {
        $user = $userId > 0 ? User::find($userId) : null;

        if (! $user) {
            throw new \RuntimeException("No notification recipient found for {$eventType}.");
        }

        return $user;
    }
}

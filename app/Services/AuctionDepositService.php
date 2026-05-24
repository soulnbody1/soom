<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionDeposit;
use App\Repositories\AuctionBidRepository;
use App\Repositories\AuctionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuctionDepositService
{
    public function __construct(
        protected AuctionRepository $auctionRepo,
        protected AuctionBidRepository $bidRepo
    ) {}

    public function payAdvertiserDeposit(Auction $auction, int $paymentSlipId): void
    {
        $this->auctionRepo->markAdvertiserDepositPaid($auction, 'PAYMENT_SLIP_' . $paymentSlipId);
    }

    public function payBidderDeposit(Auction $auction, int $userId, string $paymentMethod, array $paymentData): string
    {
        $amount = $auction->getDepositAmount();
        
        // محاكاة عملية الدفع
        $transactionId = $this->processPayment($amount, $paymentMethod, $paymentData);
        
        if (!$transactionId) {
            throw new \Exception('فشل في معالجة الدفع.');
        }

        $existingBid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);
        
        if ($existingBid) {
            $this->bidRepo->markBidDepositPaid($existingBid, $transactionId, $amount);
        } else {
            // إنشاء سجل مزايدة مبدئي لتسجيل دفع التأمين
            $bid = $this->bidRepo->create([
                'auction_id' => $auction->id,
                'user_id' => $userId,
                'amount' => 0,
                'is_winning' => null,
                'terms_accepted' => false,
            ]);
            
            // إنشاء سجل التأمين في الجدول الموحد
            AuctionDeposit::create([
                'user_id' => $userId,
                'depositable_id' => $bid->id,
                'depositable_type' => AuctionBid::class,
                'paid' => true,
                'paid_at' => now(),
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'deposit_status' => 'held',
                'deposit_type' => 'bidder',
            ]);
        }
        
        return $transactionId;
    }

    public function refundAdvertiserDeposit(Auction $auction): void
    {
        $deposit = $auction->advertiserDeposit;
        if (!$deposit || !$deposit->paid) {
            return;
        }

        $this->processRefund($deposit->transaction_id);
        $deposit->refund();
    }

    public function refundBidderDeposit(AuctionBid $bid): void
    {
        $deposit = $bid->deposit;
        if (!$deposit || !$deposit->isHeld()) {
            return;
        }

        $this->processRefund($deposit->transaction_id);
        $deposit->refund();
    }

    public function refundAllBiddersDeposits(int $auctionId): void
    {
        $this->bidRepo->refundAllDepositsForAuction($auctionId);
    }

    public function refundLosersDeposits(int $auctionId, int $winnerBidId): void
    {
        $this->bidRepo->refundDepositsForLosers($auctionId, $winnerBidId);
    }

    public function applyWinnerDepositToPayment(AuctionBid $bid): void
    {
        if (!$bid->isHeld()) {
            throw new \Exception('التأمين غير محتجز.');
        }

        $bid->applyDepositToPayment();
    }

    public function forfeitDeposit(AuctionBid $bid): void
    {
        if (!$bid->isHeld()) {
            return;
        }

        $bid->forfeitDeposit();
    }

    public function getTotalHeldDeposits(int $auctionId): float
    {
        $deposits = $this->bidRepo->getHeldDepositsForAuction($auctionId);
        
        return $deposits->sum(function ($deposit) {
            return (float) ($deposit->amount ?? 0);
        });
    }

    public function getUserHeldDeposits(int $userId): array
    {
        $deposits = $this->bidRepo->getHeldDepositsForUser($userId);
        
        return [
            'total_count' => $deposits->count(),
            'total_amount' => $deposits->sum(function ($deposit) {
                return (float) ($deposit->amount ?? 0);
            }),
            'auctions' => $deposits->pluck('depositable_id')->toArray(),
        ];
    }

    protected function processPayment(float $amount, string $method, array $data): ?string
    {
        // TODO: التكامل الحقيقي مع بوابة الدفع
        return 'TXN_' . Str::uuid();
    }

    protected function processRefund(string $transactionId): bool
    {
        // TODO: التكامل الحقيقي مع بوابة الدفع
        return true;
    }
}

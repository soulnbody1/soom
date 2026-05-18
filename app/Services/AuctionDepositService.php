<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionBid;
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

    public function payAdvertiserDeposit(Auction $auction, string $paymentMethod, array $paymentData): string
    {
        $amount = $auction->getDepositAmount();
        
        // محاكاة عملية الدفع
        $transactionId = $this->processPayment($amount, $paymentMethod, $paymentData);
        
        if (!$transactionId) {
            throw new \Exception('فشل في معالجة الدفع.');
        }

        $this->auctionRepo->markAdvertiserDepositPaid($auction, $transactionId);
        
        return $transactionId;
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
            $this->bidRepo->markBidDepositPaid($existingBid, $transactionId);
        } else {
            // إنشاء سجل مزايدة مبدئي لتسجيل دفع التأمين
            $this->bidRepo->create([
                'auction_id' => $auction->id,
                'user_id' => $userId,
                'amount' => 0,
                'is_winning' => false,
                'deposit_paid' => true,
                'deposit_paid_at' => now(),
                'deposit_transaction_id' => $transactionId,
                'deposit_status' => 'held',
                'terms_accepted' => false,
            ]);
        }
        
        return $transactionId;
    }

    public function refundAdvertiserDeposit(Auction $auction): void
    {
        if (!$auction->advertiser_deposit_paid) {
            return;
        }

        $this->processRefund($auction->advertiser_deposit_transaction_id);
        
        $auction->update([
            'advertiser_deposit_paid' => false,
        ]);
    }

    public function refundBidderDeposit(AuctionBid $bid): void
    {
        if (!$bid->isHeld()) {
            return;
        }

        $this->processRefund($bid->deposit_transaction_id);
        $bid->refundDeposit();
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
        
        return $deposits->sum(function ($bid) {
            return $bid->auction->getDepositAmount();
        });
    }

    public function getUserHeldDeposits(int $userId): array
    {
        $deposits = $this->bidRepo->getHeldDepositsForUser($userId);
        
        return [
            'total_count' => $deposits->count(),
            'total_amount' => $deposits->sum(function ($bid) {
                return $bid->auction->getDepositAmount();
            }),
            'auctions' => $deposits->pluck('auction_id')->toArray(),
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

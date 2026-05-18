<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionBid;
use App\Repositories\AuctionBidRepository;
use App\Repositories\AuctionRepository;
use Illuminate\Support\Facades\DB;

class AuctionBidService
{
    public function __construct(
        protected AuctionBidRepository $bidRepo,
        protected AuctionRepository $auctionRepo
    ) {}

    public function placeBid(Auction $auction, int $userId, float $amount, string $depositTransactionId, bool $termsAccepted = false): AuctionBid
    {
        return DB::transaction(function () use ($auction, $userId, $amount, $depositTransactionId, $termsAccepted) {
            // قفل المزاد لمنع race condition
            $lockedAuction = $this->auctionRepo->lockForUpdate($auction->id);
            
            if (!$lockedAuction || !$lockedAuction->isActive()) {
                throw new \Exception('المزاد غير متاح للمزايدة.');
            }

            // التحقق من المزايد
            $this->validateBidder($lockedAuction, $userId);

            // التحقق من المبلغ
            $this->validateBidAmount($lockedAuction, $amount);

            // التحقق من دفع التأمين
            if (!$this->isDepositPaid($auction, $userId)) {
                throw new \Exception('يجب دفع التأمين أولاً.');
            }

            // التحقق من أن الـ deposit_transaction_id يخص المستخدم الحالي
            $depositBid = $this->bidRepo->getUserBidByTransaction($auction->id, $userId, $depositTransactionId);
            if (!$depositBid) {
                throw new \Exception('معاملة التأمين غير صالحة أو لا تخصك.');
            }

            // البحث عن مزايدة موجودة (قد تكون من تسجيل التأمين)
            $existingBid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);

            if ($existingBid) {
                // تحديث المزايدة الموجودة (دفع التأمين سابقاً)
                $this->bidRepo->update($existingBid, [
                    'amount' => $amount,
                    'is_winning' => true,
                    'winning_at' => now(),
                    'deposit_transaction_id' => $depositTransactionId,
                    'terms_accepted' => $termsAccepted,
                ]);
                $bid = $existingBid->fresh();
            } else {
                // إنشاء مزايدة جديدة (حالة نادرة: لم يتم دفع التأمين عبر النظام)
                $bidData = [
                    'auction_id' => $auction->id,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'is_winning' => true,
                    'winning_at' => now(),
                    'deposit_paid' => true,
                    'deposit_paid_at' => now(),
                    'deposit_transaction_id' => $depositTransactionId,
                    'deposit_status' => 'held',
                    'terms_accepted' => $termsAccepted,
                ];
                $bid = $this->bidRepo->create($bidData);
            }

            // تحديث المزاد
            $this->auctionRepo->updateCurrentBid($lockedAuction, $amount);
            $this->auctionRepo->incrementBidsCount($lockedAuction);
            $this->auctionRepo->updateUniqueBiddersCount($lockedAuction);

            // تحديث المزايدات السابقة
            $this->bidRepo->markPreviousBidsAsOutbid($auction->id, $bid->id);

            // التمديد لو في آخر 5 دقائق
            if ($lockedAuction->shouldExtend()) {
                $lockedAuction->extend(10);
            }

            return $bid;
        });
    }

    /**
     * رفع المزايدة (زيادة مبلغ مزايدة موجودة)
     */
    public function increaseBid(Auction $auction, int $userId, float $newAmount, bool $termsAccepted): AuctionBid
    {
        return DB::transaction(function () use ($auction, $userId, $newAmount, $termsAccepted) {
            // قفل المزاد لمنع race condition
            $lockedAuction = $this->auctionRepo->lockForUpdate($auction->id);
            
            if (!$lockedAuction || !$lockedAuction->isActive()) {
                throw new \Exception('المزاد غير متاح للمزايدة.');
            }

            // جلب مزايدة المستخدم الحالية
            $existingBid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);
            
            if (!$existingBid) {
                throw new \Exception('لا يوجد مزايدة سابقة لك على هذا المزاد. استخدم "إضافة مزايدة" بدلاً من "رفع المزايدة".');
            }

            // التحقق من أن المبلغ الجديد أعلى من السعر الحالي
            $this->validateBidAmount($lockedAuction, $newAmount);

            // تحديث المزايدة
            $updateData = [
                'amount' => $newAmount,
                'is_winning' => true,
                'winning_at' => now(),
                'terms_accepted' => $termsAccepted,
            ];

            $this->bidRepo->update($existingBid, $updateData);

            // تحديث المزاد
            $this->auctionRepo->updateCurrentBid($lockedAuction, $newAmount);
            $this->auctionRepo->incrementBidsCount($lockedAuction);
            $this->auctionRepo->updateUniqueBiddersCount($lockedAuction);

            // تحديث المزايدات السابقة (جعلها غير فائزة)
            $this->bidRepo->markPreviousBidsAsOutbid($auction->id, $existingBid->id);

            // التمديد لو في آخر 5 دقائق
            if ($lockedAuction->shouldExtend()) {
                $lockedAuction->extend(10);
            }

            // إعادة المزايدة المحدثة
            $existingBid->refresh();
            $lockedAuction->refresh();
            
            return $existingBid;
        });
    }

    public function payBidDeposit(Auction $auction, int $userId, string $transactionId): void
    {
        $existingBid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);
        
        if ($existingBid && $existingBid->deposit_paid) {
            throw new \Exception('تم دفع التأمين مسبقاً.');
        }

        if ($existingBid) {
            $this->bidRepo->markBidDepositPaid($existingBid, $transactionId);
        }
    }

    public function isDepositPaid(Auction $auction, int $userId): bool
    {
        $bid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);
        return $bid && $bid->deposit_paid;
    }

    public function getBidDepositAmount(Auction $auction): float
    {
        return $auction->getDepositAmount();
    }

    public function getBidsForAuction(int $auctionId, int $perPage = 20)
    {
        return $this->bidRepo->getBidsForAuction($auctionId, $perPage);
    }

    public function getMyBids(int $userId, int $perPage = 20)
    {
        $relations = [
            'auction',
            'auction.images:id,auction_id,image_path',
            'auction.category:id,name',
            'auction.user:id,name,logo',
        ];
        
        return $this->bidRepo->getBidsForUser($userId, $relations, $perPage);
    }

    public function getHighestBid(int $auctionId): ?AuctionBid
    {
        return $this->bidRepo->getHighestBid($auctionId);
    }

    public function hasUserBid(int $auctionId, int $userId): bool
    {
        return $this->bidRepo->hasUserBid($auctionId, $userId);
    }

    protected function validateBidder(Auction $auction, int $userId): void
    {
        if ($auction->user_id === $userId) {
            throw new \Exception('لا يمكنك المزايدة على مزادك.');
        }
    }

    protected function validateBidAmount(Auction $auction, float $amount): void
    {
        $currentBid = $auction->current_bid ?? $auction->starting_price;
        
        if ($amount <= $currentBid) {
            throw new \Exception('يجب أن تكون المزايدة أعلى من السعر الحالي (' . $currentBid . ').');
        }
    }

    public function getActiveBidsCount(int $userId): int
    {
        return AuctionBid::where('user_id', $userId)
            ->whereHas('auction', function ($q) {
                $q->active();
            })
            ->count();
    }

    public function getWinningBidsCount(int $userId): int
    {
        return AuctionBid::where('user_id', $userId)
            ->where('is_winning', true)
            ->whereHas('auction', function ($q) {
                $q->active();
            })
            ->count();
    }
}

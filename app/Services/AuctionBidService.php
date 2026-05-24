<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionDeposit;
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

            // البحث عن مزايدة موجودة (قد تكون من تسجيل التأمين)
            $existingBid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);

            if ($existingBid) {
                // تحديث المزايدة الموجودة (دفع التأمين سابقاً)
            $this->bidRepo->update($existingBid, [
                'amount' => $amount,
                'is_winning' => $existingBid->user_id,
                'winning_at' => now(),
                'terms_accepted' => $termsAccepted,
            ]);
                $bid = $existingBid->fresh();
            } else {
                // إنشاء مزايدة جديدة (حالة نادرة: لم يتم دفع التأمين عبر النظام)
                $bidData = [
                    'auction_id' => $auction->id,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'is_winning' => $userId,
                    'winning_at' => now(),
                    'terms_accepted' => $termsAccepted,
                ];
                $bid = $this->bidRepo->create($bidData);
                
                // إنشاء سجل التأمين في الجدول الموحد
                AuctionDeposit::create([
                    'user_id' => $userId,
                    'depositable_id' => $bid->id,
                    'depositable_type' => AuctionBid::class,
                    'paid' => true,
                    'paid_at' => now(),
                    'transaction_id' => $depositTransactionId,
                    'amount' => $auction->getDepositAmount(),
                    'deposit_status' => 'held',
                    'deposit_type' => 'bidder',
                ]);
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
                'is_winning' => $userId,
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
        
        if ($existingBid && $existingBid->isDepositPaid()) {
            throw new \Exception('تم دفع التأمين مسبقاً.');
        }

        if ($existingBid) {
            $this->bidRepo->markBidDepositPaid($existingBid, $transactionId, $auction->getDepositAmount());
        }
    }

    public function isDepositPaid(Auction $auction, int $userId): bool
    {
        $bid = $this->bidRepo->getUserBidForAuction($auction->id, $userId);
        return $bid && $bid->isDepositPaid();
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
            ->whereNotNull('is_winning')
            ->whereHas('auction', function ($q) {
                $q->active();
            })
            ->count();
    }
}

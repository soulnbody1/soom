<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Repositories\AuctionRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AuctionService
{
    public function __construct(
        protected AuctionRepository $auctionRepo
    ) {}

    public function create(array $data, int $userId): Auction
    {
        return DB::transaction(function () use ($data, $userId) {
            // إنشاء المزاد مباشرة (بدون إعلان)
            $auctionData = [
                'user_id' => $userId,
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'description' => $data['description'],
                'country_id' => $data['country_id'],
                'state_id' => $data['state_id'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'starting_price' => $data['starting_price'],
                'min_accept_price' => $data['min_accept_price'] ?? 0,
                'deposit_amount' => $data['deposit_amount'] ?? null,
                'starts_at' => $data['starts_at'] ?? now(),
                'ends_at' => $data['ends_at'],
                'duration_days' => $data['duration_days'] ?? 0,
                'terms_accepted' => $data['terms_accepted'] ?? false,
                'status' => 'pending_payment',
            ];

            $auction = $this->auctionRepo->create($auctionData);

            // رفع الصور
            if (!empty($data['images'])) {
                $this->uploadImages($auction, $data['images']);
            }

            return $auction->fresh(['images', 'category', 'user']);
        });
    }

    protected function uploadImages(Auction $auction, array $images): void
    {
        $disk = Storage::disk('spaces');
        
        foreach ($images as $index => $image) {
            $path = $image->store('auctions/' . $auction->id, 'spaces');
            
            AuctionImage::create([
                'auction_id' => $auction->id,
                'image_path' => $path,
                'order' => $index,
            ]);
        }
    }

    public function update(Auction $auction, array $data, int $userId): Auction
    {
        if (!$this->canUpdate($auction, $userId)) {
            throw new \Exception('لا يمكن تعديل المزاد في هذه الحالة.');
        }

        return DB::transaction(function () use ($auction, $data) {
            $auctionData = array_intersect_key($data, array_flip([
                'title', 'description', 'category_id', 'country_id',
                'state_id', 'city_id', 'latitude', 'longitude',
                'starting_price', 'min_accept_price', 'deposit_amount',
                'starts_at', 'duration_days'
            ]));

            // حساب ends_at من جديد لو تغير starts_at أو duration_days
            if (isset($data['starts_at']) || isset($data['duration_days'])) {
                $startsAt = $data['starts_at'] ?? $auction->starts_at ?? now();
                $durationDays = $data['duration_days'] ?? $auction->duration_days ?? 1;
                $endsAt = \Carbon\Carbon::parse($startsAt)->addDays($durationDays);
                $auctionData['ends_at'] = $endsAt->format('Y-m-d H:i:s');
            }

            if (!empty($auctionData)) {
                $this->auctionRepo->update($auction, $auctionData);
            }

            // تحديث الصور لو في صور جديدة
            if (!empty($data['images'])) {
                // حذف الصور القديمة
                foreach ($auction->images as $oldImage) {
                    Storage::disk('spaces')->delete($oldImage->image_path);
                    $oldImage->delete();
                }
                
                // رفع الصور الجديدة
                $this->uploadImages($auction, $data['images']);
            }

            return $auction->fresh(['images', 'category', 'user']);
        });
    }

    public function payAdvertiserDeposit(Auction $auction, string $transactionId): void
    {
        if ($auction->status !== 'pending_payment') {
            throw new \Exception('المزاد ليس في حالة انتظار الدفع.');
        }

        $this->auctionRepo->markAdvertiserDepositPaid($auction, $transactionId);
        Cache::forget('home_ads_data');
    }

    public function closeAuction(Auction $auction, ?string $reason = null): void
    {
        if (!$auction->isActive()) {
            throw new \Exception('المزاد ليس نشطاً.');
        }

        $this->auctionRepo->close($auction, $reason);
    }

    public function cancelAuction(Auction $auction, int $userId, ?string $reason = null): void
    {
        if ($auction->user_id !== $userId) {
            throw new \Exception('لا يمكن إلغاء مزاد لا تملكه.');
        }

        if (!in_array($auction->status, ['draft', 'pending_payment'])) {
            throw new \Exception('لا يمكن إلغاء المزاد بعد بدئه.');
        }

        DB::transaction(function () use ($auction, $reason) {
            // حذف الصور من التخزين
            foreach ($auction->images as $image) {
                Storage::disk('spaces')->delete($image->image_path);
            }
            
            $this->auctionRepo->cancel($auction, $reason);
        });
    }

    public function finalizeAuction(Auction $auction): void
    {
        if (!$auction->isActive()) {
            return;
        }

        DB::transaction(function () use ($auction) {
            $highestBid = $auction->winningBid;

            if ($highestBid && $highestBid->amount >= $auction->min_accept_price) {
                $this->auctionRepo->setWinner($auction, $highestBid->user_id);
                $highestBid->applyDepositToPayment();
                
                foreach ($auction->bids as $bid) {
                    if ($bid->id !== $highestBid->id && $bid->isHeld()) {
                        $bid->refundDeposit();
                    }
                }
            } else {
                $this->auctionRepo->close($auction, 'لم يصل السعر للحد الأدنى');
                
                foreach ($auction->bids as $bid) {
                    if ($bid->isHeld()) {
                        $bid->refundDeposit();
                    }
                }
            }
        });
    }

    public function getAuction(int $id, array $relations = []): ?Auction
    {
        $auction = $this->auctionRepo->findById($id, $relations);
        
        if ($auction && $auction->isActive()) {
            $this->auctionRepo->incrementViewsCount($auction);
        }
        
        return $auction;
    }

    public function getActiveAuctions(array $relations = [], int $perPage = 20): LengthAwarePaginator
    {
        $defaultRelations = ['images', 'category', 'user', 'winningBid.user'];
        $relations = array_merge($defaultRelations, $relations);
        
        return $this->auctionRepo->getActiveAuctions($relations, $perPage);
    }

    public function getMyAuctions(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        $relations = ['images', 'category', 'winningBid'];
        
        return $this->auctionRepo->getAuctionsForUser($userId, $relations, $perPage);
    }

    public function canUpdate(Auction $auction, int $userId): bool
    {
        return $auction->user_id === $userId 
            && in_array($auction->status, ['draft', 'pending_payment']);
    }

    public function canCancel(Auction $auction, int $userId): bool
    {
        return $auction->user_id === $userId 
            && in_array($auction->status, ['draft', 'pending_payment']);
    }

    public function processExpiredAuctions(): int
    {
        $expiredAuctions = $this->auctionRepo->getExpiredAuctions();
        $count = 0;

        foreach ($expiredAuctions as $auction) {
            try {
                $this->finalizeAuction($auction);
                $count++;
            } catch (\Exception $e) {
                \Log::error('Failed to finalize auction ' . $auction->id . ': ' . $e->getMessage());
            }
        }

        return $count;
    }

    public function extendAuctionIfNeeded(Auction $auction): bool
    {
        if (!$auction->shouldExtend()) {
            return false;
        }

        $this->auctionRepo->extendIfNeeded($auction, 10);
        return true;
    }
}

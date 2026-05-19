<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MyAuctionResource extends JsonResource
{
    public function toArray($request): array
    {
        // جلب مزايدة المستخدم الحالي على هذا المزاد
        $userBid = $this->bids()->where('user_id', auth('sanctum')->id())->first();
        $userBidAmount = $userBid ? (float) $userBid->amount : null;
        
        // أعلى مزايدة حالياً
        $highestBidAmount = (float) ($this->current_bid ?? $this->starting_price);
        
        // تحديد الحالة
        $bidStatus = null;
        if ($userBidAmount !== null) {
            if ($userBidAmount >= $highestBidAmount) {
                $bidStatus = 'أعلى مزايد';
            } else {
                $bidStatus = 'تم تجاوزك';
            }
        }
        
        return [
            // بيانات المزاد
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->getLocation(),
            'images' => $this->images->map(function ($image) {
                return $image->image_path;
            }),
            
            // الأسعار والمزايدات
            'starting_price' => (float) $this->starting_price,
            'highest_bid_amount' => $highestBidAmount,
            'my_bid_amount' => $userBidAmount,
            'bid_status' => $bidStatus,
            'min_accept_price' => (float) $this->min_accept_price,
            
            // الوقت المتبقي
            'time_remaining' => $this->getTimeRemainingFormatted(),
            
            // الإحصائيات (محسوبة من العلاقات)
            'views_count' => (int) $this->views()->count(),
            'bids_count' => (int) $this->bids()->count(),
            'unique_bidders_count' => (int) $this->bids()->distinct('user_id')->count('user_id'),
            
            // الحالة
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'starts_at' => $this->starts_at?->toDateTimeString(),
            'ends_at' => $this->ends_at?->toDateTimeString(),
            
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
    
    protected function getLocation(): string
    {
        $parts = array_filter([
            $this->country?->name,
            $this->state?->name,
            $this->city?->name,
        ]);
        
        return implode(', ', $parts);
    }
    
    protected function getStatusLabel(): string
    {
        $labels = [
            'draft' => 'مسودة',
            'pending_payment' => 'بانتظار الدفع',
            'active' => 'نشط',
            'extended' => 'ممتد',
            'closed' => 'مغلق',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
        ];
        
        return $labels[$this->status] ?? $this->status;
    }
    
    protected function getTimeRemainingFormatted(): ?string
    {
        $seconds = $this->getTimeRemaining();
        
        if ($seconds <= 0) {
            return null;
        }
        
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($days > 0) {
            return "{$days} يوم و {$hours} س";
        } elseif ($hours > 0) {
            return "{$hours} س و {$minutes} د";
        } else {
            return "{$minutes} د";
        }
    }
}

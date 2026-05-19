<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuctionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            // بيانات المزاد
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->getLocation(),
            
            // الأسعار
            'current_bid' => (float) ($this->current_bid ?? $this->starting_price),
            'min_accept_price' => (float) $this->min_accept_price,
            
            // الوقت المتبقي
            'time_remaining' => $this->getTimeRemainingFormatted(),
            
            // الإحصائيات (محسوبة من العلاقات)
            'views_count' => (int) ($this->views_count ?? $this->views()->count()),
            'bids_count' => (int) ($this->bids_count ?? $this->bids()->count()),
            
            // بيانات المعلن (فقط لو دافع التأمين)
            'advertiser' => $this->when($this->advertiser_deposit_paid, function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'phone' => $this->user?->phone,
                    'logo' => $this->user?->logo,
                ];
            }),
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
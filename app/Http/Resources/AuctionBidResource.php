<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuctionBidResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'auction_id' => $this->auction_id,
            'amount' => (float) $this->amount,
            'is_winning' => (bool) $this->is_winning,
            'winning_at' => $this->winning_at?->toDateTimeString(),
            
            // المزايد
            'bidder' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'logo' => $this->user?->logo,
            ],
            
            // معلومات المزاد
            'auction' => $this->when($this->relationLoaded('auction'), function () {
                return [
                    'id' => $this->auction?->id,
                    'title' => $this->auction?->ad?->title,
                    'status' => $this->auction?->status,
                    'current_bid' => (float) ($this->auction?->current_bid ?? $this->auction?->starting_price),
                    'ends_at' => $this->auction?->ends_at?->toDateTimeString(),
                    'image' => $this->auction?->ad?->images->first()?->image_path,
                ];
            }),
            
            // حالة التأمين
            'deposit_paid' => (bool) $this->deposit_paid,
            'deposit_status' => $this->deposit_status,
            'deposit_status_label' => $this->getDepositStatusLabel(),
            
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    protected function getDepositStatusLabel(): string
    {
        $labels = [
            'held' => 'محتجز',
            'refunded' => 'مسترد',
            'forfeited' => 'مصادر',
            'applied_to_payment' => 'محول للدفع',
        ];
        
        return $labels[$this->deposit_status] ?? $this->deposit_status;
    }
}

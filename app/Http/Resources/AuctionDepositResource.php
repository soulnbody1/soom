<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuctionDepositResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->when($this->relationLoaded('user'), function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'phone' => $this->user?->phone,
                    'logo' => $this->user?->logo,
                ];
            }),
            'depositable_id' => $this->depositable_id,
            'depositable_type' => $this->depositable_type,
            'depositable' => $this->when($this->relationLoaded('depositable'), function () {
                return $this->getDepositableInfo();
            }),
            'paid' => (bool) $this->paid,
            'paid_at' => $this->paid_at?->toDateTimeString(),
            'amount' => (float) ($this->amount ?? 0),
            'transaction_id' => $this->transaction_id,
            'deposit_status' => $this->deposit_status,
            'deposit_status_label' => $this->getStatusLabel(),
            'deposit_type' => $this->deposit_type,
            'deposit_type_label' => $this->getTypeLabel(),
            'verified_at' => $this->verified_at?->toDateTimeString(),
            'is_verified' => $this->verified_at !== null,
            'verified_by' => $this->when($this->relationLoaded('verifiedBy'), function () {
                return $this->verifiedBy?->name;
            }),
            'processed_at' => $this->processed_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    protected function getStatusLabel(): string
    {
        $labels = [
            'held' => 'محتجز',
            'refunded' => 'مسترد',
            'forfeited' => 'مصادر',
            'applied_to_payment' => 'محول للدفع',
        ];
        return $labels[$this->deposit_status] ?? $this->deposit_status;
    }

    protected function getTypeLabel(): string
    {
        return $this->deposit_type === 'advertiser' ? 'تأمين معلن' : 'تأمين مزايد';
    }

    protected function getDepositableInfo(): ?array
    {
        if ($this->depositable_type === \App\Models\Auction::class && $this->depositable) {
            $auction = $this->depositable;
            return [
                'id' => $auction->id,
                'title' => $auction->title,
                'status' => $auction->status,
            ];
        }

        if ($this->depositable_type === \App\Models\AuctionBid::class && $this->depositable) {
            $bid = $this->depositable;
            return [
                'id' => $bid->id,
                'auction_id' => $bid->auction_id,
                'amount' => (float) $bid->amount,
            ];
        }

        return null;
    }
}
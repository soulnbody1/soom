<?php

declare(strict_types=1);

namespace App\Http\Resources\Notification;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? $data['event_type'] ?? 'notification',
            'event_type' => $data['event_type'] ?? null,
            'screen' => $data['screen'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,

            'auction_id' => $data['auction_id'] ?? null,
            'ad_id' => $data['ad_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,

            'your_bid' => $data['your_bid'] ?? null,
            'new_bid' => $data['new_bid'] ?? null,
            'time_remaining' => $data['time_remaining'] ?? null,

            'amount' => $data['amount'] ?? null,
            'advertiser_name' => $data['advertiser_name'] ?? null,
            'advertiser_phone' => $data['advertiser_phone'] ?? null,

            'has_winner' => $data['has_winner'] ?? null,
            'final_price' => $data['final_price'] ?? null,
            'winner_name' => $data['winner_name'] ?? null,
            'winner_phone' => $data['winner_phone'] ?? null,

            'image' => $data['image'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}

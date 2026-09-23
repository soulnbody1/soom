<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccountRecentAdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'price' => $this->price,
            'currency_code' => $this->currency_code,
            'market_code' => strtolower((string) $this->market?->code),
            'url' => $this->market?->webUrl('ads/'.$this->public_id),
            'country_id' => $this->country_id,
            'image' => $this->images->first()?->image_path,
            'status' => $this->deleted_at === null,
            'views_count' => (int) ($this->views_count ?? 0),
        ];
    }
}

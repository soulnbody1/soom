<?php

declare(strict_types=1);

namespace App\Http\Resources\SellerRating;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SellerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerRating = $this->resource->relationLoaded('viewerSellerRating')
            ? $this->resource->getRelation('viewerSellerRating')
            : null;
        $viewerId = $this->seller_rating_viewer_id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo' => $this->logo,
            'location' => implode(', ', array_filter([
                $this->country?->name,
                $this->state?->name,
                $this->city?->name,
            ])),
            'ads_count' => (int) $this->ads_count,
            'joined_at' => $this->created_at?->toIso8601String(),
            'rating_summary' => [
                'average' => round((float) ($this->received_seller_ratings_avg_rating ?? 0), 2),
                'count' => (int) $this->received_seller_ratings_count,
                'distribution' => $this->seller_rating_distribution,
            ],
            'viewer' => [
                'can_rate' => $viewerId !== null && (int) $viewerId !== (int) $this->id,
                'rating' => $viewerRating === null ? null : new SellerRatingResource($viewerRating),
            ],
        ];
    }
}

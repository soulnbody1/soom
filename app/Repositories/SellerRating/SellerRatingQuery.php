<?php

declare(strict_types=1);

namespace App\Repositories\SellerRating;

use App\Models\SellerRating;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class SellerRatingQuery
{
    public function sellerProfile(User $seller, ?int $viewerId): User
    {
        $seller->loadMissing(['country:id,name', 'state:id,name', 'city:id,name'])
            ->loadCount(['ads', 'receivedSellerRatings'])
            ->loadAvg('receivedSellerRatings', 'rating');

        $totals = SellerRating::query()
            ->where('seller_id', $seller->id)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $distribution = [];

        foreach (range(5, 1) as $rating) {
            $distribution[] = [
                'rating' => $rating,
                'count' => (int) ($totals[$rating] ?? 0),
            ];
        }

        $seller->setAttribute('seller_rating_distribution', $distribution);
        $seller->setAttribute('seller_rating_viewer_id', $viewerId);
        $seller->setRelation(
            'viewerSellerRating',
            $viewerId === null
                ? null
                : SellerRating::query()
                    ->with('reviewer:id,name,logo')
                    ->where('seller_id', $seller->id)
                    ->where('reviewer_id', $viewerId)
                    ->first()
        );

        return $seller;
    }

    public function paginate(User $seller, int $perPage): LengthAwarePaginator
    {
        return SellerRating::query()
            ->where('seller_id', $seller->id)
            ->with('reviewer:id,name,logo')
            ->latest('id')
            ->paginate($perPage);
    }
}

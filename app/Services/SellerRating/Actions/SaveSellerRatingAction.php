<?php

declare(strict_types=1);

namespace App\Services\SellerRating\Actions;

use App\Models\SellerRating;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SaveSellerRatingAction
{
    public function execute(User $reviewer, User $seller, array $data): SellerRating
    {
        if ($reviewer->is($seller)) {
            throw ValidationException::withMessages([
                'seller' => ['لا يمكنك تقييم نفسك.'],
            ]);
        }

        $rating = SellerRating::query()->updateOrCreate(
            [
                'seller_id' => $seller->id,
                'reviewer_id' => $reviewer->id,
            ],
            [
                'rating' => $data['rating'],
                'message' => $data['message'],
            ]
        );

        return $rating->load('reviewer:id,name,logo');
    }
}

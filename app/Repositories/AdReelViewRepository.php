<?php

namespace App\Repositories;

use App\Models\AdReel;
use App\Models\Category;
use App\Models\Favorite;
use Carbon\Carbon;

class AdReelViewRepository
{

    public function getReelsForUser(?object $user): array
    {
        $userId = $user?->id;
        $userStateId = $user?->state_id;
        $since = Carbon::now()->subDay();

        $allReelsQuery = AdReel::query()
            ->where('created_at', '>=', $since);

        // استثناء الريلز التي أنشأها المستخدم نفسه
        if ($userId) {
            $allReelsQuery->whereDoesntHave('ad', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            });
        }

        // الفلترة بناءً على المحافظة
        $allReelsQuery->whereHas('ad', function ($query) use ($userStateId) {
            $query->whereNull('deleted_at');

            if ($userStateId) {
                $query->where('state_id', $userStateId);
            }
        });

        // تحميل الريلز مع العلاقة
        $allReels = $allReelsQuery
            ->with([
                'ad:id,user_id,state_id,title,description,price',
                'ad.user:id,name,logo,phone',
            ])
            ->when($userId, function ($query) use ($userId) {
                $query->withCount([
                    'views as viewed_by_user' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    }
                ])->orderBy('viewed_by_user', 'asc');
            })
            ->latest()
            ->paginate(10);

        // تحميل الإعلانات التي أضافها المستخدم إلى المفضلة دفعة واحدة
        $favoritedAdIds = $userId
            ? Favorite::where('user_id', $userId)->pluck('ad_id')->toArray()
            : [];

        // تعديل المجموعة لإضافة is_favorit
        $allReels->getCollection()->transform(function ($reel) use ($favoritedAdIds) {
            $reel->is_favorit = in_array($reel->ad?->id, $favoritedAdIds) ? 1 : 0;
            return $reel;
        });

        // الريلز الخاصة بالمستخدم (my_reels)
        $myReels = [];

        if ($userId) {
            $myReels = AdReel::whereHas('ad', function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->whereNull('deleted_at');
            })
                ->where('created_at', '>=', $since)
                ->with([
                    'ad:id,user_id,title,description,price',
                    'ad.user:id,name,logo'
                ])
                ->withCount('views')
                ->latest()
                ->get();
        }

        return [
            'all_reels' => $allReels,
            'my_reels' => $myReels,
        ];
    }
    public function getReelsForSingleCategory(?object $user,array $category): array
    {
        $userId = $user?->id;
        $userStateId = $user?->state_id;
        $since = Carbon::now()->subDay();

        // الريلز العامة (all_reels) لفئة معينة
        $allReelsQuery = AdReel::query()
            ->where('created_at', '>=', $since)
            ->whereHas('ad', function ($q) use ($userStateId, $userId, $category) {
                $q->whereNull('deleted_at')
                    ->whereIn('category_id', $category);

                if ($userStateId) {
                    $q->where('state_id', $userStateId);
                }

                if ($userId) {
                    $q->where('user_id', '!=', $userId);
                }
            });

        $allReels = $allReelsQuery
            ->with([
                'ad:id,user_id,category_id,state_id,title,description,price',
                'ad.user:id,name,logo,phone',
            ])
            ->when($userId, function ($query) use ($userId) {
                $query->withCount([
                    'views as viewed_by_user' => function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    }
                ])->orderBy('viewed_by_user', 'asc');
            })
            ->latest()
            ->paginate(10);

        // تجهيز المفضلات
        $favoritedAdIds = $userId
            ? Favorite::where('user_id', $userId)->pluck('ad_id')->toArray()
            : [];

        $allReels->getCollection()->transform(function ($reel) use ($favoritedAdIds) {
            $reel->is_favorit = in_array($reel->ad?->id, $favoritedAdIds) ? 1 : 0;
            return $reel;
        });

        // الريلز الخاصة بالمستخدم في نفس التصنيف (my_reels)
        $myReels = [];

        if ($userId) {
            $myReels = AdReel::whereHas('ad', function ($query) use ($userId, $category) {
                $query->where('user_id', $userId)
                    ->whereIn('category_id', $category)
                    ->whereNull('deleted_at');
            })
                ->where('created_at', '>=', $since)
                ->with([
                    'ad:id,user_id,category_id,title,description,price',
                    'ad.user:id,name,logo',
                ])
                ->withCount('views')
                ->latest()
                ->get();
        }

        return [
            'all_reels' => $allReels,
            'my_reels' => $myReels,
        ];
    }
}

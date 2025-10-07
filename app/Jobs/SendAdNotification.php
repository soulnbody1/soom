<?php

namespace App\Jobs;

use App\Models\Ad;
use App\Models\User;
use App\Notifications\NewAdNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\UserAdInteraction;
use App\Jobs\SendFcmNotification;


class SendAdNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Ad $ad) {}

    public function handle(): void
    {
        $category = $this->ad->category;
        $categoryIds = $this->getAllCategoryIdsIncludingParents($category);

        $interactedUserIds = UserAdInteraction::whereHas('ad', function ($q) use ($categoryIds) {
            $q->whereIn('category_id', $categoryIds);
        })
            ->whereIn('action', ['click', 'save'])
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= 3')
            ->pluck('user_id');



        User::whereIn('id', $interactedUserIds)
            ->where('city_id', $this->ad->city_id)
            ->where('id', '!=', $this->ad->user_id)
            ->where('allow_ad_notifications', true)
            ->chunk(100, function ($usersChunk) {
                foreach ($usersChunk as $user) {
                    Notification::send($user, new NewAdNotification($this->ad));
                    if ($user->fcm_token) {
                        SendFcmNotification::dispatch(
                            $user->fcm_token,
                            '📢 إعلان جديد',
                            $this->ad->title,
                            [
                                'ad_id' => $this->ad->id,
                                'category_id' => $this->ad->category_id,
                            ]
                        );
                    }
                }
            });
    }

    protected function getAllCategoryIdsIncludingParents($category): array
    {
        static $cache = [];

        if (isset($cache[$category->id])) {
            return $cache[$category->id];
        }

        $ids = [$category->id];
        while ($category->parent) {
            $category = $category->parent;
            $ids[] = $category->id;
        }

        return $cache[$category->id] = $ids;
    }
}

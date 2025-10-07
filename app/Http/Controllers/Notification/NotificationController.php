<?php

namespace App\Http\Controllers\Notification;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $paginator = $request->user()->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        $notifications = $paginator->getCollection()->map(function ($notification) {
            return [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? null,
                'message' => $notification->data['message'] ?? null,
                'ad_id' => $notification->data['ad_id'] ?? null,
                'category_id' => $notification->data['category_id'] ?? null,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at->toDateTimeString(),
            ];
        });

        $paginator->setCollection($notifications);

        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'notifications' => $paginator,
        ]);
    }



    public function markAsRead(Request $request)
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();
        return response()->json(['message' => 'All notifications marked as read.']);
    }
}

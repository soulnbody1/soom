<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexNotificationsRequest;
use App\Http\Resources\Notification\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request): JsonResponse
    {
        $user = $request->user();

        $paginator = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate($request->perPage());

        $paginator->setCollection(
            NotificationResource::collection($paginator->getCollection())->collection
        );

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $paginator,
        ]);
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $updated = $request->user()
            ->unreadNotifications()
            ->toBase()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    public function markSingleAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }
}

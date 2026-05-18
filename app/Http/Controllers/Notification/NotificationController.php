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
            $data = $notification->data;
            
            return [
                'id' => $notification->id,
                'type' => $data['type'] ?? 'notification',
                'title' => $data['title'] ?? null,
                'message' => $data['message'] ?? null,
                
                // Auction specific fields
                'auction_id' => $data['auction_id'] ?? null,
                'ad_id' => $data['ad_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                
                // Outbid notification
                'your_bid' => $data['your_bid'] ?? null,
                'new_bid' => $data['new_bid'] ?? null,
                'time_remaining' => $data['time_remaining'] ?? null,
                
                // Won notification
                'amount' => $data['amount'] ?? null,
                'advertiser_name' => $data['advertiser_name'] ?? null,
                'advertiser_phone' => $data['advertiser_phone'] ?? null,
                
                // Ended notification (for advertiser)
                'has_winner' => $data['has_winner'] ?? null,
                'final_price' => $data['final_price'] ?? null,
                'winner_name' => $data['winner_name'] ?? null,
                'winner_phone' => $data['winner_phone'] ?? null,
                
                // Common fields
                'image' => $data['image'] ?? null,
                'unread_count' => $data['unread_count'] ?? null,
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

    public function markSingleAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        
        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread_count' => $request->user()->unreadNotifications()->count()
        ]);
    }
}

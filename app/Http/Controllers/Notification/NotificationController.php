<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexNotificationsRequest;
use App\Http\Resources\Notification\NotificationResource;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'الإشعارات', description: 'إشعارات المستخدم وتسجيل أجهزة استقبال الإشعارات وحالة القراءة.', weight: 10)]
class NotificationController extends Controller
{
    #[Endpoint(title: 'عرض إشعارات المستخدم', description: 'يعرض إشعارات المستخدم من الأحدث إلى الأقدم مع عدد الإشعارات غير المقروءة وتقسيم النتائج إلى صفحات.')]
    #[Response(200, description: 'الإشعارات وبيانات الصفحات وعدد العناصر غير المقروءة.')]
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

    #[Endpoint(title: 'تعليم جميع الإشعارات كمقروءة', description: 'يحدّث جميع إشعارات المستخدم غير المقروءة دفعة واحدة.')]
    #[Response(200, description: 'عدد الإشعارات التي تم تحديثها وحالة العداد بعد العملية.')]
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

    #[Endpoint(title: 'تعليم إشعار واحد كمقروء', description: 'يعلّم إشعارًا محددًا يخص المستخدم الحالي كمقروء ويعيد العداد المحدث.')]
    #[PathParameter('id', description: 'المعرّف الخاص بالإشعار.')]
    #[Response(200, description: 'تم تعليم الإشعار كمقروء وإرجاع العداد المحدث.')]
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'حساب المستخدم', description: 'بيانات الحساب الخاصة بالمستخدم الحالي ومؤشرات التنقل المختصرة.', weight: 8)]
final class AccountShellController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly UnreadConversationCounter $unreadConversations,
    ) {}

    #[Endpoint(
        title: 'عرض ملخص واجهة الحساب',
        description: 'يعرض بيانات المستخدم الحالي مع عدد محادثاته غير المقروءة وعدد إشعاراته غير المقروءة في استجابة واحدة خفيفة لاستخدامها في شريط التنقل وصفحات الحساب.'
    )]
    #[Response(200, description: 'بيانات المستخدم ومؤشرات الرسائل والإشعارات غير المقروءة.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    #[Response(403, description: 'الحساب لا يملك صلاحية استخدام واجهة المستخدم.')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['country', 'state', 'city'])->loadCount('ads');

        return $this->sendResponse([
            'user' => (new UserResource($user))->resolve($request),
            'unread_messages_count' => $this->unreadConversations->forUser((int) $user->getAuthIdentifier()),
            'unread_notifications_count' => $user->unreadNotifications()->count(),
        ], 'تم جلب ملخص الحساب بنجاح.');
    }
}

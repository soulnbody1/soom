<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserConversationsIndexRequest;
use App\Http\Resources\User\Admin\AdminUserConversationResource;
use App\Http\Resources\User\Admin\AdminUserMessageResource;
use App\Models\User;
use App\Repositories\User\Queries\UserConversationsQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'ملف المستخدم الإداري', description: 'عرض شامل للمستخدم داخل لوحة الإدارة: بياناته وحسابه ونشاطه ومحتواه ومزاداته ومعاملاته المالية ومحادثاته. جميع نقاط النهاية للقراءة فقط ومتاحة للمشرفين.', weight: 20)]
final class AdminUserConversationController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض محادثات المستخدم',
        description: 'يعرض محادثات المستخدم داخل المنصة مجمّعةً حسب الطرف الآخر، مع آخر رسالة وتاريخها وعدد الرسائل وعدد غير المقروء، مرتبةً من الأحدث نشاطًا. تُحمَّل الرسائل نفسها من نقطة نهاية المحادثة عند فتحها.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'محادثات المستخدم مقسّمة إلى صفحات.')]
    public function index(AdminUserConversationsIndexRequest $request, User $user, UserConversationsQuery $conversations): JsonResponse
    {
        Gate::authorize('viewConversations', $user);

        $paginator = $conversations->paginateThreads($user, $request->perPage(), $request->page())
            ->through(fn (array $thread) => new AdminUserConversationResource($thread));

        return $this->sendResponse($paginator, __('admin_user.messages.conversations_fetched'));
    }

    #[Endpoint(
        title: 'عرض رسائل محادثة المستخدم',
        description: 'يعرض رسائل المحادثة بين المستخدم والطرف الآخر مرتبةً من الأحدث إلى الأقدم ومقسّمة إلى صفحات، مع الإعلان المرتبط بالرسالة إن وُجد. عرض للقراءة فقط ولا يغيّر حالة القراءة.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[PathParameter('partner', description: 'معرّف الطرف الآخر في المحادثة.')]
    #[Response(200, description: 'رسائل المحادثة مقسّمة إلى صفحات.')]
    public function messages(AdminUserConversationsIndexRequest $request, User $user, User $partner, UserConversationsQuery $conversations): JsonResponse
    {
        Gate::authorize('viewConversations', $user);

        $paginator = $conversations->paginateMessages($user, $partner, $request->perPage(20))
            ->through(fn ($message) => new AdminUserMessageResource($message));

        return $this->sendResponse(
            $paginator,
            __('admin_user.messages.messages_fetched'),
            200,
            ['partner' => ['id' => $partner->id, 'name' => $partner->name, 'logo' => $partner->logo]]
        );
    }
}

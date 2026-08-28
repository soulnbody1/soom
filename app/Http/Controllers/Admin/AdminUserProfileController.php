<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserActivityIndexRequest;
use App\Http\Requests\Admin\AdminUserAdsIndexRequest;
use App\Http\Resources\User\Admin\AdminUserActivityResource;
use App\Http\Resources\User\Admin\AdminUserAdResource;
use App\Http\Resources\User\Admin\AdminUserProfileResource;
use App\Models\User;
use App\Repositories\User\Queries\UserActivityQuery;
use App\Repositories\User\Queries\UserContentQuery;
use App\Repositories\User\Queries\UserProfileOverviewQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'ملف المستخدم الإداري', description: 'عرض شامل للمستخدم داخل لوحة الإدارة: بياناته وحسابه ونشاطه ومحتواه ومزاداته ومعاملاته المالية ومحادثاته. جميع نقاط النهاية للقراءة فقط ومتاحة للمشرفين.', weight: 20)]
final class AdminUserProfileController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض النظرة العامة لملف المستخدم',
        description: 'يعرض بطاقة تعريف المستخدم وحالة حسابه وعناوينه وأرقام ملخّصة خفيفة لنشاطه داخل المنصة، إضافةً إلى آخر أحداثه المهمة. مصمّمة لتكون خفيفة؛ تفاصيل كل قسم تُجلب من نقطة النهاية الخاصة به عند فتح تبويبه.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'بيانات المستخدم مع ملخّص نشاطه وآخر أحداثه.')]
    public function show(
        User $user,
        UserProfileOverviewQuery $overview,
        UserActivityQuery $activity
    ): JsonResponse {
        Gate::authorize('viewAdminProfile', $user);

        $user->load(['country:id,name', 'state:id,name', 'city:id,name']);

        $payload = (new AdminUserProfileResource($user))->resolve();
        $payload['session'] = $overview->sessionTimestamps($user);
        $payload['counts'] = $overview->counts($user);
        $payload['recent_activity'] = AdminUserActivityResource::collection($activity->recent($user, 8))->resolve();

        return $this->sendResponse($payload, __('admin_user.messages.profile_fetched'));
    }

    #[Endpoint(
        title: 'عرض إعلانات المستخدم',
        description: 'يعرض الإعلانات التي أنشأها المستخدم مع تصنيفها ومدينتها وعدد مشاهداتها وعدد من أضافها إلى مفضّلته وحالتها، مع دعم التصفية بالحالة والتصنيف والبحث بالعنوان.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'إعلانات المستخدم مقسّمة إلى صفحات.')]
    public function ads(AdminUserAdsIndexRequest $request, User $user, UserContentQuery $content): JsonResponse
    {
        Gate::authorize('viewAdminProfile', $user);

        $paginator = $content->paginateAds($user, $request->filters(), $request->perPage())
            ->through(fn ($ad) => new AdminUserAdResource($ad));

        return $this->sendResponse($paginator, __('admin_user.messages.ads_fetched'));
    }

    #[Endpoint(
        title: 'عرض مفضّلة المستخدم',
        description: 'يعرض الإعلانات التي أضافها المستخدم إلى مفضّلته مرتبةً من الأحدث، مع تاريخ الإضافة.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'مفضّلة المستخدم مقسّمة إلى صفحات.')]
    public function favorites(AdminUserAdsIndexRequest $request, User $user, UserContentQuery $content): JsonResponse
    {
        Gate::authorize('viewAdminProfile', $user);

        $paginator = $content->paginateFavorites($user, $request->perPage())
            ->through(fn ($ad) => new AdminUserAdResource($ad));

        return $this->sendResponse($paginator, __('admin_user.messages.favorites_fetched'));
    }

    #[Endpoint(
        title: 'عرض العناصر المحفوظة للمستخدم',
        description: 'يعرض الإعلانات التي حفظها المستخدم عبر تفاعلات الحفظ المسجّلة في المنصة، مرتبةً من الأحدث.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'العناصر المحفوظة مقسّمة إلى صفحات.')]
    public function saved(AdminUserAdsIndexRequest $request, User $user, UserContentQuery $content): JsonResponse
    {
        Gate::authorize('viewAdminProfile', $user);

        $paginator = $content->paginateSavedAds($user, $request->perPage())
            ->through(fn ($interaction) => [
                'saved_at' => $interaction->created_at?->toIso8601String(),
                'ad' => $interaction->ad ? (new AdminUserAdResource($interaction->ad))->resolve() : null,
            ]);

        return $this->sendResponse($paginator, __('admin_user.messages.saved_fetched'));
    }

    #[Endpoint(
        title: 'عرض سجل نشاط المستخدم',
        description: 'يعرض الأحداث التي نفّذها المستخدم داخل نظام المزادات مرتبةً من الأحدث إلى الأقدم، مع نوع الحدث والمزاد المرتبط به والبيانات المرافقة. يمكن تصفية الأحداث بنوعها أو قصرها على مزاد واحد.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'أحداث المستخدم مقسّمة إلى صفحات مع قائمة أنواع الأحداث المتاحة له.')]
    public function activity(AdminUserActivityIndexRequest $request, User $user, UserActivityQuery $activity): JsonResponse
    {
        Gate::authorize('viewAdminProfile', $user);

        $paginator = $activity->paginate($user, $request->filters(), $request->perPage(20))
            ->through(fn ($entry) => new AdminUserActivityResource($entry));

        return $this->sendResponse(
            $paginator,
            __('admin_user.messages.activity_fetched'),
            200,
            ['event_types' => $activity->eventTypes($user)]
        );
    }
}

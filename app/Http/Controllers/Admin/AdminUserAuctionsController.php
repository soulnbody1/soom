<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserAuctionsIndexRequest;
use App\Http\Resources\User\Admin\AdminUserAuctionResource;
use App\Models\User;
use App\Repositories\User\Queries\UserAuctionsQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'ملف المستخدم الإداري', description: 'عرض شامل للمستخدم داخل لوحة الإدارة: بياناته وحسابه ونشاطه ومحتواه ومزاداته ومعاملاته المالية ومحادثاته. جميع نقاط النهاية للقراءة فقط ومتاحة للمشرفين.', weight: 20)]
final class AdminUserAuctionsController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض مزادات المستخدم',
        description: 'يعرض مزادات المستخدم حسب النطاق المطلوب: المزادات التي أنشأها بائعًا، أو التي سجّل فيها مزايدًا، أو التي فاز بها، أو التي انتهت دون فوزه. في نطاقات المشاركة تُرفق بكل مزاد بيانات مشاركة المستخدم نفسه: حالة تسجيله وعدد مزايداته وأعلى مزايدة له وحالة تأمينه وحالة تسويته إن كان فائزًا.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'مزادات المستخدم مقسّمة إلى صفحات مع بيانات مشاركته في كل مزاد.')]
    public function index(AdminUserAuctionsIndexRequest $request, User $user, UserAuctionsQuery $auctions): JsonResponse
    {
        Gate::authorize('viewAdminProfile', $user);

        $scope = $request->scope();

        $paginator = $auctions->paginate($user, $scope, $request->filters(), $request->perPage())
            ->through(fn ($auction) => new AdminUserAuctionResource($auction));

        return $this->sendResponse(
            $paginator,
            __('admin_user.messages.auctions_fetched'),
            200,
            ['scope' => $scope]
        );
    }
}

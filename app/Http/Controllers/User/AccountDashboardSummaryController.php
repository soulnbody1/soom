<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountRecentAdResource;
use App\Repositories\User\Queries\AccountDashboardSummaryQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'حساب المستخدم', description: 'بيانات الحساب الخاصة بالمستخدم الحالي ومؤشرات التنقل المختصرة.', weight: 8)]
final class AccountDashboardSummaryController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(title: 'عرض ملخص لوحة الحساب', description: 'يعرض العدادات الإجمالية وثلاثة إعلانات حديثة بصيغة خفيفة دون تحميل قوائم الحساب الكاملة.')]
    #[Response(200, description: 'عدادات لوحة الحساب والإعلانات الحديثة.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    public function __invoke(Request $request, AccountDashboardSummaryQuery $summary): JsonResponse
    {
        $data = $summary->forUser((int) $request->user()->id);
        $data['recent_ads'] = AccountRecentAdResource::collection($data['recent_ads'])->resolve($request);

        return $this->sendResponse($data, 'تم جلب ملخص لوحة الحساب بنجاح.');
    }
}

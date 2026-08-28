<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserFinancialIndexRequest;
use App\Http\Resources\Auction\MoneyResource;
use App\Http\Resources\User\Admin\AdminUserDepositResource;
use App\Http\Resources\User\Admin\AdminUserPaymentSubmissionResource;
use App\Http\Resources\User\Admin\AdminUserPaymentTransactionResource;
use App\Http\Resources\User\Admin\AdminUserPayoutDestinationResource;
use App\Http\Resources\User\Admin\AdminUserPayoutResource;
use App\Http\Resources\User\Admin\AdminUserRefundResource;
use App\Models\User;
use App\Repositories\User\Queries\UserFinancialQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'ملف المستخدم الإداري', description: 'عرض شامل للمستخدم داخل لوحة الإدارة: بياناته وحسابه ونشاطه ومحتواه ومزاداته ومعاملاته المالية ومحادثاته. جميع نقاط النهاية للقراءة فقط ومتاحة للمشرفين.', weight: 20)]
final class AdminUserFinanceController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض الملخّص المالي للمستخدم',
        description: 'يعرض عدد السجلات وإجمالي مبالغها لكل نوع مالي مرتبط بالمستخدم (الاستردادات والمستحقات والتأمينات وإيصالات الدفع والمعاملات) مجمّعةً حسب الحالة والعملة، لاستخدامها كبطاقات ملخّص قبل فتح الجداول التفصيلية.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'الملخّص المالي مصنّفًا بحسب النوع والحالة والعملة.')]
    public function summary(User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        $summary = [];
        foreach ($finance->summary($user) as $section => $rows) {
            $summary[$section] = array_map(fn (array $row): array => [
                'status' => $row['status'],
                'count' => $row['count'],
                'total' => MoneyResource::make($row['total_minor'], $row['currency']),
            ], $rows);
        }

        return $this->sendResponse($summary, __('admin_user.messages.financial_summary_fetched'));
    }

    #[Endpoint(
        title: 'عرض استردادات المستخدم',
        description: 'يعرض عمليات استرداد الأموال المرتبطة بالمستخدم مع المبلغ وسببها ووجهة التحويل المستخدمة وحالتها ومراحلها الزمنية، ويوضّح ما إذا كان لها إثبات تحويل مرفوع دون كشف مسار الملف.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'استردادات المستخدم مقسّمة إلى صفحات.')]
    public function refunds(AdminUserFinancialIndexRequest $request, User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        $paginator = $finance->paginateRefunds($user, $request->filters(), $request->perPage())
            ->through(fn ($refund) => new AdminUserRefundResource($refund));

        return $this->sendResponse($paginator, __('admin_user.messages.refunds_fetched'));
    }

    #[Endpoint(
        title: 'عرض مستحقات المستخدم كبائع',
        description: 'يعرض مستحقات المستخدم الناتجة عن مزاداته المكتملة مع صافي المبلغ ورسوم المنصة ووجهة التحويل ومرجع التحويل وحالة الصرف وأسباب التعليق أو الإخفاق إن وُجدت.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'مستحقات المستخدم مقسّمة إلى صفحات.')]
    public function payouts(AdminUserFinancialIndexRequest $request, User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        $paginator = $finance->paginatePayouts($user, $request->filters(), $request->perPage())
            ->through(fn ($payout) => new AdminUserPayoutResource($payout));

        return $this->sendResponse($paginator, __('admin_user.messages.payouts_fetched'));
    }

    #[Endpoint(
        title: 'عرض تأمينات المستخدم',
        description: 'يعرض تأمينات المستخدم كبائع أو كمزايد مع المبلغ المطلوب والمحجوز والمطبَّق والمسترد والمصادَر وحالة كل تأمين وسبب حجزه.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'تأمينات المستخدم مقسّمة إلى صفحات.')]
    public function deposits(AdminUserFinancialIndexRequest $request, User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        $paginator = $finance->paginateDeposits($user, $request->filters(), $request->perPage())
            ->through(fn ($deposit) => new AdminUserDepositResource($deposit));

        return $this->sendResponse($paginator, __('admin_user.messages.deposits_fetched'));
    }

    #[Endpoint(
        title: 'عرض إيصالات دفع المستخدم',
        description: 'يعرض إيصالات الدفع التي رفعها المستخدم مع الغرض منها ووسيلة الدفع ومرجع التحويل وحالة المراجعة وملاحظة المراجع، ويوضّح وجود إيصال مرفوع دون كشف مساره في التخزين.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'إيصالات دفع المستخدم مقسّمة إلى صفحات.')]
    public function submissions(AdminUserFinancialIndexRequest $request, User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        $paginator = $finance->paginateSubmissions($user, $request->filters(), $request->perPage())
            ->through(fn ($submission) => new AdminUserPaymentSubmissionResource($submission));

        return $this->sendResponse($paginator, __('admin_user.messages.submissions_fetched'));
    }

    #[Endpoint(
        title: 'عرض المعاملات المالية للمستخدم',
        description: 'يعرض المعاملات المالية المسجّلة للمستخدم بعد اعتماد إيصالاته، مع الغرض والمبلغ ومزوّد الدفع وحالة المعاملة وتاريخ معالجتها، دون أي بيانات داخلية للمزوّد.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'المعاملات المالية مقسّمة إلى صفحات.')]
    public function transactions(AdminUserFinancialIndexRequest $request, User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        $paginator = $finance->paginateTransactions($user, $request->filters(), $request->perPage())
            ->through(fn ($transaction) => new AdminUserPaymentTransactionResource($transaction));

        return $this->sendResponse($paginator, __('admin_user.messages.transactions_fetched'));
    }

    #[Endpoint(
        title: 'عرض وجهات التحويل الخاصة بالمستخدم',
        description: 'يعرض وجهات التحويل المحفوظة للمستخدم التي تُستخدم في الاستردادات وصرف المستحقات، مع اسم المستفيد ونوع المعرّف وقيمته والوجهة الافتراضية وتواريخ الإنشاء والتعديل. للقراءة فقط.'
    )]
    #[PathParameter('user', description: 'معرّف المستخدم الرقمي.')]
    #[Response(200, description: 'قائمة وجهات التحويل المحفوظة للمستخدم.')]
    public function destinations(User $user, UserFinancialQuery $finance): JsonResponse
    {
        Gate::authorize('viewFinancialProfile', $user);

        return $this->sendResponse(
            AdminUserPayoutDestinationResource::collection($finance->destinations($user)),
            __('admin_user.messages.destinations_fetched')
        );
    }
}

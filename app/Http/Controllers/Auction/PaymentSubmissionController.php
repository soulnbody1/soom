<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AdminPaymentSubmissionIndexRequest;
use App\Http\Requests\Auction\ReviewPaymentSubmissionRequest;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Models\Auction\PaymentSubmission;
use App\Services\Auction\Actions\ListPaymentSubmissionsAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

#[Group(name: 'إثباتات الدفع', description: 'إثباتات التحويل التي يرفعها المستخدمون ومراجعتها من المشرف.', weight: 6)]
final class PaymentSubmissionController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض إثباتات الدفع',
        description: 'يعرض إثباتات الدفع المرفوعة في جميع المزادات مع إمكانية التصفية بالحالة أو بغرض الدفعة أو بالمزاد.'
    )]
    #[Response(200, description: 'قائمة إثباتات الدفع مقسّمة إلى صفحات.')]
    public function index(AdminPaymentSubmissionIndexRequest $request, ListPaymentSubmissionsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', PaymentSubmission::class);

        return $this->sendResponse(
            PaymentSubmissionResource::collection($action->execute($request->filters(), $request->perPage())),
            __('auction.messages.payment_submissions_fetched')
        );
    }

    #[Endpoint(
        title: 'مراجعة إثبات الدفع',
        description: 'يسجّل قرار المشرف على إثبات الدفع. الاعتماد يرصد الدفعة ويكمل المرحلة المرتبطة بها في المزاد، والرفض يطالب صاحبها بإعادة الإرسال. اعتماد دفعة بعد انقضاء مهلتها يتطلب صلاحية تجاوز المهلة ومبررًا مكتوبًا.'
    )]
    #[PathParameter('paymentSubmission', description: 'المعرّف العام لإثبات الدفع (ULID).')]
    #[Response(200, description: 'إثبات الدفع بعد تطبيق قرار المراجعة.')]
    public function review(
        ReviewPaymentSubmissionRequest $request,
        PaymentSubmission $paymentSubmission,
        ReviewPaymentSubmissionAction $action
    ): JsonResponse {
        $data = $request->validated();
        Gate::authorize($data['action'] === 'approve' ? 'approve' : 'reject', $paymentSubmission);
        if ($data['action'] === 'approve' && (bool) ($data['override_deadline'] ?? false)) {
            Gate::authorize('overrideDeadline', $paymentSubmission);
        }

        $submission = $data['action'] === 'approve'
            ? $action->approve(
                $paymentSubmission,
                Auth::id(),
                (string) ($data['note'] ?? 'approved'),
                (string) ($data['provider_transaction_id'] ?? ''),
                (bool) ($data['override_deadline'] ?? false),
                (string) ($data['override_reason'] ?? '')
            )
            : $action->reject($paymentSubmission, Auth::id(), (string) $data['note']);

        return $this->sendResponse(new PaymentSubmissionResource($submission), __('auction.messages.payment_submission_reviewed'));
    }

    #[Endpoint(
        title: 'إنشاء رابط مؤقت لإيصال الدفع',
        description: 'ينشئ رابطًا مؤقتًا لتحميل ملف الإيصال المرفوع. الإيصال محفوظ في مخزن خاص (S3/Spaces) لا يمكن الوصول إليه مباشرة، فيُصدر النظام رابط pre-signed URL صالحًا لعشر دقائق فقط ويُرجع معه تاريخ انتهاء صلاحيته في expires_at. تُنفَّذ هذه العملية عبر مسارين لهما السلوك نفسه ويختلفان في الجمهور: مسار المستخدم GET /api/soom/payment-submissions/{paymentSubmission}/receipt-url لصاحب الإثبات ليعيد فتح إيصاله، ومسار الإدارة GET /api/admin/auctions/payment-submissions/{paymentSubmission}/receipt-url للمشرف أثناء مراجعة الدفعات. وفي الحالتين يُطبَّق التفويض نفسه: صاحب الإثبات أو من يملك صلاحية مراجعة الدفعات. يُرجع 404 إذا تعذّر إنشاء الرابط من مخزن الملفات.'
    )]
    #[PathParameter('paymentSubmission', description: 'المعرّف العام لإثبات الدفع (ULID).')]
    #[Response(200, description: 'الرابط المؤقت وتاريخ انتهاء صلاحيته.')]
    public function receiptUrl(PaymentSubmission $paymentSubmission): JsonResponse
    {
        Gate::authorize('viewReceipt', $paymentSubmission);

        try {
            $url = Storage::disk($paymentSubmission->receipt_disk)
                ->temporaryUrl($paymentSubmission->receipt_path, now()->addMinutes(10));
        } catch (\Throwable) {
            return $this->sendError(__('auction.errors.receipt_url_unavailable'), 404, 'receipt_url_unavailable');
        }

        return $this->sendResponse([
            'url' => $url,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], __('auction.messages.payment_receipt_url_created'));
    }
}

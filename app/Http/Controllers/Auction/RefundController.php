<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ConfirmAuctionRefundRequest;
use App\Http\Resources\Auction\MoneyResource;
use App\Models\Auction\PayoutDestination;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\PayoutDestinationRepository;
use App\Services\Auction\Actions\CancelAuctionRefundAction;
use App\Services\Auction\Actions\ConfirmAuctionRefundManuallyAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

#[Group(name: 'عمليات الاسترداد', description: 'متابعة عمليات استرداد التأمينات والمبالغ ومعالجتها يدويًا من المشرف.', weight: 9)]
final class RefundController extends Controller
{
    use ApiResponseTrait;

    private const PROOF_URL_TTL_MINUTES = 10;

    #[Endpoint(
        title: 'عرض عمليات الاسترداد',
        description: 'يعرض عمليات الاسترداد في جميع المزادات مع المبلغ والحالة وعدد المحاولات وآخر خطأ، ووجهة التحويل الخاصة بالعميل، مع إمكانية التصفية بالحالة أو بالمزاد أو بالمستخدم.'
    )]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[QueryParameter('status', description: 'تصفية عمليات الاسترداد بحالتها.')]
    #[QueryParameter('auction_id', description: 'تصفية عمليات الاسترداد بالمعرّف العام للمزاد.')]
    #[QueryParameter('user_id', description: 'تصفية عمليات الاسترداد بمعرّف المستخدم صاحب المبلغ.')]
    #[Response(200, description: 'قائمة عمليات الاسترداد مقسّمة إلى صفحات مع وجهة التحويل لكل عملية.')]
    public function index(
        Request $request,
        AuctionRefundRepository $refunds,
        PayoutDestinationRepository $destinations
    ): JsonResponse {
        Gate::authorize('viewAny', RefundTransaction::class);

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($status) => $status->value, RefundTransactionStatus::cases()))],
            'auction_id' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $refunds->paginateForAdmin($filters, min(100, max(1, (int) $request->input('per_page', 20))));

        $defaults = $destinations->defaultsForUsers(
            collect($paginator->items())->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all()
        );

        return $this->sendResponse(
            $paginator->through(fn (RefundTransaction $refund): array => $this->refundPayload(
                $refund,
                $defaults[(int) $refund->user_id] ?? null
            )),
            __('auction.messages.refunds_fetched')
        );
    }

    #[Endpoint(
        title: 'تأكيد استرداد يدويًا',
        description: 'يسجّل أن المشرف نفّذ الاسترداد خارج المنصة وينقل العملية إلى حالة الاسترداد المكتمل، مع توثيق الرقم المرجعي للتحويل. تُثبَّت وجهة التحويل المستخدمة فعليًا على سجل الاسترداد وقت التأكيد، فإن لم تكن للعميل وجهة محفوظة وجب على المشرف إرسال وجهة بديلة كاملة وإلا رُفض الطلب. يُرسل الطلب بصيغة multipart/form-data عند إرفاق ملف الإثبات.'
    )]
    #[PathParameter('refund', description: 'المعرّف العام لعملية الاسترداد (ULID).')]
    #[Response(200, description: 'عملية الاسترداد بعد تأكيدها مع وجهة التحويل المثبّتة عليها.')]
    public function confirm(
        ConfirmAuctionRefundRequest $request,
        RefundTransaction $refund,
        ConfirmAuctionRefundManuallyAction $action
    ): JsonResponse {
        Gate::authorize('confirmManual', $refund);

        $confirmed = $action->execute(
            $refund,
            $request->user(),
            (string) $request->validated('confirmation_reference'),
            (string) $request->validated('reason'),
            destinationOverride: $request->destinationOverride(),
            proof: $request->file('proof'),
        );

        return $this->sendResponse($this->refundPayload($confirmed), __('auction.messages.refund_confirmed'));
    }

    #[Endpoint(
        title: 'إلغاء عملية استرداد',
        description: 'يوقف عملية الاسترداد ويمنع إعادة محاولتها تلقائيًا، مع توثيق سبب الإلغاء.'
    )]
    #[PathParameter('refund', description: 'المعرّف العام لعملية الاسترداد (ULID).')]
    #[BodyParameter('reason', description: 'سبب إلغاء عملية الاسترداد.')]
    #[Response(200, description: 'عملية الاسترداد بعد إلغائها.')]
    public function cancel(
        Request $request,
        RefundTransaction $refund,
        CancelAuctionRefundAction $action
    ): JsonResponse {
        Gate::authorize('cancel', $refund);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $cancelled = $action->execute($refund, $request->user(), (string) $data['reason']);

        return $this->sendResponse($this->refundPayload($cancelled), __('auction.messages.refund_cancelled'));
    }

    #[Endpoint(
        title: 'إنشاء رابط مؤقت لإثبات الاسترداد',
        description: 'ينشئ رابطًا مؤقتًا صالحًا لعشر دقائق لتحميل ملف إثبات التحويل الذي أرفقه المشرف عند تأكيد الاسترداد. يُرجع 404 إذا لم يكن للعملية إثبات مرفوع أو تعذّر إنشاء الرابط.'
    )]
    #[PathParameter('refund', description: 'المعرّف العام لعملية الاسترداد (ULID).')]
    #[Response(200, description: 'الرابط المؤقت وتاريخ انتهاء صلاحيته.')]
    public function proofUrl(RefundTransaction $refund): JsonResponse
    {
        Gate::authorize('viewProof', $refund);

        if ($refund->proof_path === null) {
            return $this->sendError(__('auction.errors.refund_proof_unavailable'), 404, 'refund_proof_unavailable');
        }

        try {
            $url = Storage::disk((string) $refund->proof_disk)
                ->temporaryUrl($refund->proof_path, now()->addMinutes(self::PROOF_URL_TTL_MINUTES));
        } catch (\Throwable) {
            return $this->sendError(__('auction.errors.refund_proof_unavailable'), 404, 'refund_proof_unavailable');
        }

        return $this->sendResponse([
            'url' => $url,
            'expires_at' => now()->addMinutes(self::PROOF_URL_TTL_MINUTES)->toIso8601String(),
        ], __('auction.messages.refund_proof_url_created'));
    }

    private function refundPayload(RefundTransaction $refund, ?PayoutDestination $fallback = null): array
    {
        $refund->loadMissing([
            'auction:id,public_id,title',
            'user:id,name',
            'paymentTransaction',
        ]);

        return [
            'public_id' => $refund->public_id,
            'auction' => $refund->auction ? [
                'id' => $refund->auction->public_id,
                'title' => $refund->auction->title,
            ] : null,
            'owner' => $refund->user ? [
                'id' => $refund->user->id,
                'name' => $refund->user->name,
            ] : null,
            'amount' => MoneyResource::make((int) $refund->amount_minor, (string) $refund->currency_code),
            'currency' => $refund->currency_code,
            'status' => $refund->status->value,
            'reason' => $refund->reason,
            'attempt_count' => (int) $refund->attempt_count,
            'last_error' => $refund->last_error,
            'captured' => $this->capturedPayload($refund),
            'destination' => $this->destinationPayload($refund, $fallback),
            'has_proof' => $refund->proof_path !== null,
            'created_at' => $refund->created_at?->toIso8601String(),
            'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
        ];
    }

    private function capturedPayload(RefundTransaction $refund): ?array
    {
        $payment = $refund->paymentTransaction;

        if (! $payment || $payment->captured_amount_minor === null) {
            return null;
        }

        return [
            'amount_minor' => (int) $payment->captured_amount_minor,
            'currency_code' => $payment->captured_currency_code,
            'matches_refund' => (int) $payment->captured_amount_minor === (int) $refund->amount_minor
                && strtoupper((string) $payment->captured_currency_code) === strtoupper((string) $refund->currency_code),
            'provider' => $payment->provider,
            'provider_transaction_id' => $payment->provider_transaction_id,
            'failure_code' => $payment->failure_code,
        ];
    }

    private function destinationPayload(RefundTransaction $refund, ?PayoutDestination $fallback): ?array
    {
        if ($refund->hasDestinationSnapshot()) {
            return [
                'source' => 'snapshot',
                'recipient_name' => $refund->recipient_name,
                'identifier_type' => $refund->identifier_type,
                'identifier_value' => $refund->identifier_value,
            ];
        }

        $default = $fallback ?? app(PayoutDestinationRepository::class)->defaultFor((int) $refund->user_id);

        return $default === null ? null : [
            'source' => 'user_default',
            'recipient_name' => $default->recipient_name,
            'identifier_type' => $default->identifier_type,
            'identifier_value' => $default->identifier_value,
        ];
    }
}

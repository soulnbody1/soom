<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\MoneyResource;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRefundRepository;
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
use Illuminate\Validation\Rule;

#[Group(name: 'عمليات الاسترداد', description: 'متابعة عمليات استرداد التأمينات والمبالغ ومعالجتها يدويًا من المشرف.', weight: 9)]
final class RefundController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض عمليات الاسترداد',
        description: 'يعرض عمليات الاسترداد في جميع المزادات مع المبلغ والحالة وعدد المحاولات وآخر خطأ، مع إمكانية التصفية بالحالة أو بالمزاد أو بالمستخدم.'
    )]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[QueryParameter('status', description: 'تصفية عمليات الاسترداد بحالتها.')]
    #[QueryParameter('auction_id', description: 'تصفية عمليات الاسترداد بالمعرّف العام للمزاد.')]
    #[QueryParameter('user_id', description: 'تصفية عمليات الاسترداد بمعرّف المستخدم صاحب المبلغ.')]
    #[Response(200, description: 'قائمة عمليات الاسترداد مقسّمة إلى صفحات.')]
    public function index(Request $request, AuctionRefundRepository $refunds): JsonResponse
    {
        Gate::authorize('viewAny', RefundTransaction::class);

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($status) => $status->value, RefundTransactionStatus::cases()))],
            'auction_id' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $refunds
            ->paginateForAdmin($filters, min(100, max(1, (int) $request->input('per_page', 20))))
            ->through(fn (RefundTransaction $refund): array => $this->refundPayload($refund));

        return $this->sendResponse($paginator, __('auction.messages.refunds_fetched'));
    }

    #[Endpoint(
        title: 'تأكيد استرداد يدويًا',
        description: 'يسجّل أن المشرف نفّذ الاسترداد خارج المنصة وينقل العملية إلى حالة الاسترداد المكتمل، مع توثيق الرقم المرجعي للتحويل.'
    )]
    #[PathParameter('refund', description: 'المعرّف العام لعملية الاسترداد (ULID).')]
    #[BodyParameter('confirmation_reference', description: 'الرقم المرجعي للتحويل المنفَّذ خارج المنصة.')]
    #[BodyParameter('reason', description: 'مبرر التأكيد اليدوي.')]
    #[Response(200, description: 'عملية الاسترداد بعد تأكيدها.')]
    public function confirm(
        Request $request,
        RefundTransaction $refund,
        ConfirmAuctionRefundManuallyAction $action
    ): JsonResponse {
        Gate::authorize('confirmManual', $refund);

        $data = $request->validate([
            'confirmation_reference' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $confirmed = $action->execute(
            $refund,
            $request->user(),
            (string) $data['confirmation_reference'],
            (string) $data['reason']
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

    private function refundPayload(RefundTransaction $refund): array
    {
        $refund->loadMissing([
            'auction:id,public_id,title',
            'user:id,name',
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
            'created_at' => $refund->created_at?->toIso8601String(),
            'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\DTO\Auction\PaymentRecordDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AdminPaymentRecordIndexRequest;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\Queries\AdminPaymentRecordQuery;
use App\Services\Auction\Actions\ListPaymentRecordsAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Support\FinancialObligationKey;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'عمليات الدفع', description: 'سجل موحّد لعمليات الدفع اليدوية والإلكترونية في المزادات.', weight: 6)]
final class PaymentRecordController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly AdminPaymentRecordQuery $records,
    ) {}

    #[Endpoint(
        title: 'عرض عمليات الدفع',
        description: 'يعرض سجلًا موحّدًا لعمليات الدفع في جميع المزادات: التحويلات اليدوية بانتظار المراجعة والعمليات المالية المنفّذة يدويًا أو إلكترونيًا، مع عدّاد لكل حالة يحترم بقية عوامل التصفية.'
    )]
    #[Response(200, description: 'قائمة عمليات الدفع مقسّمة إلى صفحات مع عدّاد الحالات.')]
    public function index(AdminPaymentRecordIndexRequest $request, ListPaymentRecordsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', PaymentSubmission::class);

        $filters = $request->filters();
        $paginator = $action->execute($filters, $request->perPage(), $request->page());

        return $this->sendResponse(
            $paginator->through(fn (Model $record): array => PaymentRecordDTO::from($record)->toArray()),
            __('auction.messages.payment_records_fetched'),
            200,
            ['status_counts' => $action->statusCounts($filters)]
        );
    }

    #[Endpoint(
        title: 'تفاصيل عملية دفع',
        description: 'يعرض دورة حياة عملية الدفع كاملة: القناة والمزوّد والمبالغ المحصّلة وحالة المراجعة والعربون المرتبط وعمليات الاسترداد السابقة. لا يعرض حمولة المزوّد أو أي بيانات حساسة.'
    )]
    #[PathParameter('record', description: 'المعرّف العام لعملية الدفع أو لإثبات الدفع (ULID).')]
    #[Response(200, description: 'تفاصيل عملية الدفع.')]
    public function show(string $record): JsonResponse
    {
        Gate::authorize('viewAny', PaymentSubmission::class);

        $found = $this->records->findByPublicId($record);

        if (! $found) {
            return $this->sendError(__('auction.errors.payment_transaction_not_found'), 404, 'payment_transaction_not_found');
        }

        return $this->sendResponse(
            PaymentRecordDTO::from($found, $this->depositFor($found))->detail(),
            __('auction.messages.payment_record_fetched')
        );
    }

    #[Endpoint(
        title: 'بدء استرداد عملية دفع',
        description: 'يبدأ استرداد العربون المرتبط بعملية دفع ناجحة عبر مسار الاسترداد القائم، ويحسب المبلغ القابل للاسترداد تلقائيًا بعد خصم ما طُبِّق على التسوية وما سبق استرداده. العملية idempotent فلا ينشأ استرداد مكرر عند إعادة الطلب.'
    )]
    #[PathParameter('record', description: 'المعرّف العام لعملية الدفع (ULID).')]
    #[BodyParameter('reason', description: 'سبب بدء الاسترداد.')]
    #[Response(201, description: 'عملية الاسترداد بعد إنشائها.')]
    public function refund(Request $request, string $record, RefundAuctionDepositAction $action): JsonResponse
    {
        Gate::authorize('create', RefundTransaction::class);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $found = $this->records->findByPublicId($record);

        if (! $found instanceof PaymentTransaction || $found->status !== PaymentTransactionStatus::Succeeded) {
            return $this->sendError(__('auction.errors.refund_not_available'), 422, 'refund_not_available');
        }

        $deposit = $this->depositFor($found);

        if (! $deposit) {
            return $this->sendError(__('auction.errors.refund_not_available'), 422, 'refund_not_available');
        }

        $refund = $action->execute($deposit, (string) $data['reason'], (int) Auth::id());

        return $this->sendResponse([
            'id' => $refund->public_id,
            'status' => $refund->status->value,
        ], __('auction.messages.refund_started'), 201);
    }

    private function depositFor(PaymentTransaction|PaymentSubmission $record): ?AuctionDeposit
    {
        if ($record instanceof PaymentSubmission) {
            return $record->deposit;
        }

        $depositId = FinancialObligationKey::depositId((string) $record->successful_obligation_key);

        return $depositId === null ? null : AuctionDeposit::with('refunds')->find($depositId);
    }
}

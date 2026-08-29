<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreatePaymentIntentRequest;
use App\Http\Resources\Auction\PaymentTransactionResource;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\RefreshOnlinePaymentAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'الدفع الإلكتروني', description: 'إنشاء عمليات الدفع الإلكتروني ومتابعة حالتها. لا تُعتمد حالة الدفع إلا بعد تحقق الخادم من مزوّد الدفع.', weight: 6)]
final class OnlinePaymentController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'بدء عملية دفع إلكتروني',
        description: 'ينشئ عملية دفع إلكتروني معلّقة للالتزام المستحق ويعيد تعليمات الانتقال إلى صفحة الدفع لدى المزوّد. يُقرأ المبلغ والعملة من إعدادات المزاد المثبّتة ولا يُقبلان من العميل. إعادة الطلب أثناء وجود عملية معلّقة صالحة تعيد التعليمات نفسها دون إنشاء عملية جديدة.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'تفاصيل عملية الدفع وتعليمات الانتقال إلى المزوّد.')]
    public function store(
        CreatePaymentIntentRequest $request,
        Auction $auction,
        CreatePaymentIntentAction $action
    ): JsonResponse {
        if (! Gate::allows('view', $auction)) {
            return $this->sendError(__('auction.errors.auction_not_found'), 404, 'auction_not_found');
        }

        $purpose = match ($request->validated('purpose')) {
            'bidder_deposit' => PaymentPurpose::BidderDeposit,
            'seller_deposit' => PaymentPurpose::SellerDeposit,
            default => PaymentPurpose::WinnerSettlement,
        };

        $transaction = $action->execute(
            $auction,
            (int) Auth::id(),
            $purpose,
            (string) $request->validated('payment_method_id')
        );

        return $this->sendResponse(
            new PaymentTransactionResource($transaction),
            __('auction.messages.online_payment_intent_created'),
            201
        );
    }

    #[Endpoint(
        title: 'الاستعلام عن حالة دفعة إلكترونية',
        description: 'يعيد حالة الدفعة بعد التحقق من مزوّد الدفع عبر الخادم. تُستدعى بعد عودة المستخدم من صفحة الدفع، ولا تُغيّر الحالة اعتمادًا على أي معطى قادم من المتصفح.'
    )]
    #[PathParameter('paymentTransaction', description: 'المعرّف العام لعملية الدفع (ULID).')]
    #[Response(200, description: 'حالة عملية الدفع بعد التحقق من المزوّد.')]
    public function show(
        string $paymentTransaction,
        AuctionPaymentRepository $payments,
        RefreshOnlinePaymentAction $refresh
    ): JsonResponse {
        $transaction = $payments->findTransactionForUser($paymentTransaction, (int) Auth::id());

        if (! $transaction || ! $transaction->isOnline()) {
            return $this->sendError(__('auction.errors.payment_transaction_not_found'), 404, 'payment_transaction_not_found');
        }

        return $this->sendResponse(
            new PaymentTransactionResource($refresh->execute($transaction)),
            __('auction.messages.online_payment_status_fetched')
        );
    }
}

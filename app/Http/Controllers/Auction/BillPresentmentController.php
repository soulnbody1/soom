<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Http\Controllers\Controller;
use App\Services\Auction\Actions\ResolveBillPresentmentAction;
use App\Services\Auction\Payments\Contracts\PresentsBills;
use App\Services\Auction\Payments\PaymentProviderFactory;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

#[Group(name: 'الدفع الإلكتروني', description: 'إنشاء عمليات الدفع الإلكتروني ومتابعة حالتها. لا تُعتمد حالة الدفع إلا بعد تحقق الخادم من مزوّد الدفع.', weight: 6)]
final class BillPresentmentController extends Controller
{
    #[Endpoint(
        title: 'استعلام مزوّد الدفع عن المطالبات المستحقة',
        description: 'نقطة استقبال استعلام مزوّد الفواتير عن المطالبات المفتوحة لمرجع دافع. عملية قراءة فقط لا تغيّر أي حالة مالية ولا تنشئ التزامًا. يتحقق المزوّد من هوية الطالب ويصوغ الرد بصيغة بروتوكوله. لا تُستخدم من الواجهة ولا تتطلب مصادقة مستخدم.'
    )]
    #[PathParameter('provider', description: 'رمز مزوّد الدفع المسجّل في إعدادات المنصة.')]
    #[Response(200, description: 'المطالبات القابلة للدفع أو سبب عدم وجودها، بصيغة المزوّد.')]
    public function handle(
        Request $request,
        string $provider,
        PaymentProviderFactory $providers,
        ResolveBillPresentmentAction $action
    ): HttpResponse {
        if (! $providers->isRegistered($provider) || ! $providers->isEnabled($provider)) {
            throw AuctionException::domain('payment_provider_unknown', ['code' => $provider], 404);
        }

        $instance = $providers->make($provider);

        if (! $instance instanceof PresentsBills) {
            throw AuctionException::domain('payment_provider_bill_presentment_unsupported', ['code' => $provider], 404);
        }

        if (strlen((string) $request->getContent()) > $this->maxBodyBytes()) {
            return $instance->renderBillQueryFailure(
                $request,
                AuctionException::domain('payment_bill_query_payload_too_large', [], 413)
            );
        }

        try {
            $query = $instance->parseBillQuery($request);
        } catch (Throwable $error) {
            return $instance->renderBillQueryFailure($request, $error);
        }

        return $instance->renderBills($query, $action->execute($query));
    }

    private function maxBodyBytes(): int
    {
        return max(1024, (int) config('auction.payments.webhook_max_body_bytes', 65536));
    }
}

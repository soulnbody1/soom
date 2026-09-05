<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Services\Auction\Actions\HandlePaymentWebhookAction;
use App\Services\Auction\Payments\Contracts\RendersProviderResponse;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

#[Group(name: 'الدفع الإلكتروني', description: 'إنشاء عمليات الدفع الإلكتروني ومتابعة حالتها. لا تُعتمد حالة الدفع إلا بعد تحقق الخادم من مزوّد الدفع.', weight: 6)]
final class PaymentWebhookController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'استقبال إشعار مزوّد الدفع',
        description: 'نقطة استقبال إشعارات مزوّد الدفع. يُسجَّل الحدث أولًا ثم يُتحقق من توقيعه، ثم تُقرأ الحالة النهائية من المزوّد عبر الخادم قبل أي تغيير مالي. الأحداث المكررة تُرفض بمفتاح فريد ولا تُنتج أثرًا. لا تُستخدم من الواجهة ولا تتطلب مصادقة مستخدم.'
    )]
    #[PathParameter('provider', description: 'رمز مزوّد الدفع المسجّل في إعدادات المنصة.')]
    #[Response(200, description: 'نتيجة معالجة الإشعار: processed أو duplicate أو unmatched.')]
    public function handle(
        Request $request,
        string $provider,
        HandlePaymentWebhookAction $action,
        PaymentProviderFactory $providers
    ): HttpResponse {
        $renderer = $this->renderer($provider, $providers);

        try {
            $outcome = $action->execute($provider, $request);
        } catch (Throwable $error) {
            if ($renderer) {
                return $renderer->renderEventFailure($request, $error);
            }

            throw $error;
        }

        if ($renderer) {
            return $renderer->renderEventResponse($request, $outcome);
        }

        return $this->sendResponse(
            ['result' => $outcome],
            __('auction.messages.payment_webhook_received')
        );
    }

    /**
     * Providers whose protocol prescribes its own acknowledgement envelope own
     * the response body; everyone else keeps the platform's default shape.
     */
    private function renderer(string $provider, PaymentProviderFactory $providers): ?RendersProviderResponse
    {
        if (! $providers->isRegistered($provider) || ! $providers->isEnabled($provider)) {
            return null;
        }

        try {
            $instance = $providers->make($provider);
        } catch (Throwable) {
            return null;
        }

        return $instance instanceof RendersProviderResponse ? $instance : null;
    }
}

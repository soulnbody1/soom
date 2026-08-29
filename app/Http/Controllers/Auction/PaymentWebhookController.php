<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Services\Auction\Actions\HandlePaymentWebhookAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function handle(Request $request, string $provider, HandlePaymentWebhookAction $action): JsonResponse
    {
        return $this->sendResponse(
            ['result' => $action->execute($provider, $request)],
            __('auction.messages.payment_webhook_received')
        );
    }
}

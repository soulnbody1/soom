<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction\Auction;
use App\Services\Auction\Actions\DescribePaymentProvidersAction;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

#[Group(name: 'إعدادات المزادات', description: 'إصدارات إعدادات المزادات المالية والزمنية والإعدادات التشغيلية المشتقة من بيئة التشغيل.', weight: 14)]
final class PaymentProviderController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض مزوّدي الدفع الإلكتروني',
        description: 'يعرض مزوّدي الدفع المسجّلين في بيئة التشغيل مع بيان تفعيلهم وتوفّر بيانات الاعتماد وقدراتهم. لا تُعاد المفاتيح ولا الأسرار في أي حال.'
    )]
    #[Response(200, description: 'قائمة المزوّدين وحالتهم.')]
    public function index(DescribePaymentProvidersAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse(
            array_values($action->execute()),
            __('auction.messages.payment_providers_fetched')
        );
    }

    #[Endpoint(
        title: 'اختبار الاتصال بمزوّد الدفع',
        description: 'يتحقق من إمكانية تحميل مزوّد الدفع وقراءة قدراته ومن اكتمال بيانات اعتماده، ويعيد زمن الاستجابة. لا يُنشئ أي عملية دفع.'
    )]
    #[PathParameter('provider', description: 'رمز مزوّد الدفع.')]
    #[Response(200, description: 'نتيجة الاختبار وزمن الاستجابة.')]
    public function test(string $provider, PaymentProviderFactory $providers, DescribePaymentProvidersAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        if (! $providers->isRegistered($provider)) {
            return $this->sendError(__('auction.errors.payment_provider_unknown', ['code' => $provider]), 404, 'payment_provider_unknown');
        }

        $startedAt = microtime(true);

        try {
            $providers->make($provider)->capabilities();
        } catch (Throwable $exception) {
            return $this->sendResponse([
                'ok' => false,
                'error_code' => 'provider_unavailable',
                'error_message' => mb_substr($exception->getMessage(), 0, 190),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], __('auction.messages.payment_provider_tested'));
        }

        $described = $action->describe($provider);

        return $this->sendResponse([
            'ok' => $described['status'] === 'ready',
            'error_code' => $described['status'] === 'ready' ? null : $described['status'],
            'error_message' => null,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ], __('auction.messages.payment_provider_tested'));
    }
}

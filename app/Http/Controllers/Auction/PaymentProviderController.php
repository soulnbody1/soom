<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\CustomerFeeBasis;
use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentCountry;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentRail;
use App\Domain\Auction\ValueObjects\Currency;
use App\Http\Controllers\Controller;
use App\Models\Auction\Auction;
use App\Services\Auction\Actions\DescribePaymentProvidersAction;
use App\Services\Auction\Payments\Contracts\VerifiesProviderConnection;
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
        title: 'عرض خيارات إعداد طرق الدفع',
        description: 'يعرض القوائم المعتمدة لإعداد طرق الدفع: القنوات والمسارات والأغراض والدول والعملات المدعومة. تُستخدم لبناء قوائم الاختيار في لوحة التحكم بدل الإدخال النصي الحر.'
    )]
    #[Response(200, description: 'خيارات إعداد طرق الدفع.')]
    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse([
            'channels' => array_column(PaymentChannel::cases(), 'value'),
            'rails' => array_column(PaymentRail::cases(), 'value'),
            'purposes' => array_column(PaymentPurpose::cases(), 'value'),
            'countries' => array_map(
                static fn (PaymentCountry $country): array => [
                    'code' => $country->value,
                    'label' => $country->label(),
                    'default_currency' => $country->defaultCurrency(),
                ],
                PaymentCountry::cases()
            ),
            'currencies' => Currency::supportedCodes(),
            'fee_bases' => array_column(CustomerFeeBasis::cases(), 'value'),
        ], __('auction.messages.payment_method_options_fetched'));
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
            $resolved = $providers->make($provider);
            $resolved->capabilities();

            if ($resolved instanceof VerifiesProviderConnection) {
                $resolved->verifyConnection();
            }
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

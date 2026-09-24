<?php

namespace App\Http\Controllers;

use App\Models\Market;
use App\Support\Market\MarketContext;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'الأسواق', description: 'الأسواق المتاحة وإعداد السوق الحالي وبيانات الربط الخاصة بكل سوق.', weight: 14)]
final class MarketController extends Controller
{
    #[Endpoint(title: 'عرض الأسواق النشطة', description: 'يعرض الأسواق النشطة المتاحة للتطبيق مع الدولة والعملة والروابط والإعدادات المحلية.')]
    #[Response(200, description: 'قائمة الأسواق النشطة.')]
    public function index(): JsonResponse
    {
        $markets = Market::query()
            ->where('is_active', true)->with('country:id,name,iso2')
            ->orderBy('code')->get()->map(fn (Market $market) => $this->present($market));

        return response()->json(['data' => $markets]);
    }

    #[Endpoint(title: 'عرض إعداد السوق الحالي', description: 'يعرض إعداد السوق الذي تم تحديده من اسم مضيف طلب الـAPI الحالي.')]
    #[Response(200, description: 'بيانات السوق الحالي وروابطه وإعداداته المحلية.')]
    public function current(MarketContext $context): JsonResponse
    {
        return response()->json(['data' => $this->present($context->market()->load('country:id,name,iso2'))]);
    }

    #[Endpoint(title: 'عرض جميع الأسواق للإدارة', description: 'يعرض جميع الأسواق داخل لوحة الإدارة بما فيها الأسواق غير النشطة.')]
    #[Response(200, description: 'قائمة الأسواق كاملة مع حالة تفعيل كل سوق.')]
    public function adminIndex(): JsonResponse
    {
        $markets = Market::query()->with('country:id,name,iso2')
            ->orderBy('code')->get()->map(fn (Market $market) => [
                ...$this->present($market),
                'is_active' => $market->is_active,
            ]);

        return response()->json(['data' => $markets]);
    }

    private function present(Market $market): array
    {
        return [
            'code' => strtolower($market->code),
            'country_id' => $market->country_id,
            'country_code' => $market->country?->iso2,
            'country_name' => $market->country?->name,
            'currency_code' => $market->currency_code,
            'timezone' => $market->timezone,
            'locale' => $market->default_locale,
            'phone_prefix' => $market->phone_country_code,
            'features' => $market->features ?? [],
            'url' => $market->web_host === null ? null : 'https://'.$market->web_host,
            'api_url' => $market->api_host === null ? null : 'https://'.$market->api_host.'/api',
        ];
    }
}

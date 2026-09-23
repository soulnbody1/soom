<?php

namespace App\Http\Controllers;

use App\Models\Market;
use App\Support\Market\MarketContext;
use Illuminate\Http\JsonResponse;

final class MarketController extends Controller
{
    public function index(): JsonResponse
    {
        $markets = Market::query()
            ->where('is_active', true)->with('country:id,name,iso2')
            ->orderBy('code')->get()->map(fn (Market $market) => $this->present($market));

        return response()->json(['data' => $markets]);
    }

    public function current(MarketContext $context): JsonResponse
    {
        return response()->json(['data' => $this->present($context->market()->load('country:id,name,iso2'))]);
    }

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

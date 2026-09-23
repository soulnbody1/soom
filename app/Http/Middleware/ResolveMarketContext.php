<?php

namespace App\Http\Middleware;

use App\Models\Market;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveMarketContext
{
    public function __construct(private readonly MarketContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->host());

        if ($this->isAdminHost($host, $request)) {
            abort_unless($request->is('api/admin/*', 'api/auth/*'), 404);

            return $this->context->run($this->adminState($request), fn (): Response => $next($request));
        }

        abort_if($request->is('api/admin/*'), 404);

        $legacy = $this->legacyMarket($host);
        $market = $legacy ?? Market::query()
            ->where('api_host', $host)
            ->where('is_active', true)
            ->first();

        if ($market === null && app()->environment('local', 'testing') && in_array($host, ['localhost', '127.0.0.1'], true)) {
            $market = Market::query()
                ->where('code', (string) config('markets.development_market_code'))
                ->where('is_active', true)
                ->first();
        }

        abort_if($market === null, 404);

        $response = $this->context->run(MarketState::marketRequest($market), fn (): Response => $next($request));

        return $legacy === null ? $response : $this->markDeprecated($response);
    }

    private function legacyMarket(string $host): ?Market
    {
        $legacyHost = strtolower((string) config('markets.legacy_api_host'));

        if ($legacyHost === '' || $legacyHost !== $host) {
            return null;
        }

        return Market::query()
            ->where('code', (string) config('markets.legacy_market_code'))
            ->where('is_active', true)
            ->first();
    }

    private function markDeprecated(Response $response): Response
    {
        $response->headers->set('Deprecation', 'true');

        $sunset = config('markets.legacy_sunset');
        if (is_string($sunset) && $sunset !== '') {
            $response->headers->set('Sunset', $sunset);
        }

        return $response;
    }

    private function adminState(Request $request): MarketState
    {
        $code = $request->query('market');

        if ($code === null || $code === '') {
            return MarketState::adminAll();
        }

        abort_unless(is_string($code) && preg_match('/^[a-zA-Z]{2}$/', $code), 422, 'Invalid market.');
        $market = Market::query()->where('code', strtoupper($code))->first();
        abort_if($market === null, 422, 'Invalid market.');

        return MarketState::adminMarket($market);
    }

    private function isAdminHost(string $host, Request $request): bool
    {
        return $host === strtolower((string) config('markets.admin_api_host'))
            || (app()->environment('local', 'testing')
                && in_array($host, ['localhost', '127.0.0.1'], true)
                && $request->is('api/admin/*'));
    }
}

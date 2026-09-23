<?php

namespace App\Http\Middleware;

use App\Support\Market\MarketContext;
use App\Support\Market\MarketMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireAdminMarket
{
    public function __construct(private MarketContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->state()->mode === MarketMode::AdminMarket, 422, 'market_required');

        return $next($request);
    }
}

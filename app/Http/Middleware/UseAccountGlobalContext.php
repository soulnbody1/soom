<?php

namespace App\Http\Middleware;

use App\Support\Market\MarketContext;
use App\Support\Market\MarketState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UseAccountGlobalContext
{
    public function __construct(private MarketContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->context->run(MarketState::accountGlobal(), fn (): Response => $next($request));
    }
}

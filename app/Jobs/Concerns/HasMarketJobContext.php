<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\InitializeMarketContext;

trait HasMarketJobContext
{
    public function middleware(): array
    {
        return [app(InitializeMarketContext::class)];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Implemented by providers whose event endpoint must answer in the provider's
 * own envelope instead of the platform's default JSON body.
 */
interface RendersProviderResponse
{
    /**
     * @param  string  $outcome  processed|duplicate|unmatched
     */
    public function renderEventResponse(Request $request, string $outcome): Response;

    public function renderEventFailure(Request $request, Throwable $error): Response;
}

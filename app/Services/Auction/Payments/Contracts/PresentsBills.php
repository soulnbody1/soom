<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Contracts;

use App\Services\Auction\Payments\Bills\BillQuery;
use App\Services\Auction\Payments\Bills\BillResolution;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Implemented by providers that ask the platform, synchronously, what a payer
 * owes before letting them pay it.
 *
 * Optional, like VerifiesProviderConnection: the presentment endpoint checks for
 * it with instanceof, so no action ever branches on a provider code.
 *
 * Implementations own authentication of the inbound request, the wire format in
 * both directions, and the translation of BillRejectionReason into whatever
 * codes their protocol defines.
 */
interface PresentsBills
{
    /**
     * @throws Throwable when the request is unauthenticated or unreadable
     */
    public function parseBillQuery(Request $request): BillQuery;

    public function renderBills(BillQuery $query, BillResolution $resolution): Response;

    public function renderBillQueryFailure(Request $request, Throwable $error): Response;
}

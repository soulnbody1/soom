<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction\Auction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AuctionOperationalSettingsController extends Controller
{
    use ApiResponseTrait;

    public function show(): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse([
            'deadlines' => [
                'winner_payment_grace_period_hours' => (int) config('auction.deadlines.winner_payment_grace_period_hours'),
                'winner_payment_reminder_hours_before' => array_values((array) config('auction.deadlines.winner_payment_reminder_hours_before', [])),
                'handover_reminder_hours_before' => array_values((array) config('auction.deadlines.handover_reminder_hours_before', [])),
                'seller_deposit_deadline_hours' => (int) config('auction.deadlines.seller_deposit_deadline_hours'),
                'seller_deposit_expiry_lease_minutes' => (int) config('auction.deadlines.seller_deposit_expiry_lease_minutes'),
                'review_sla_hours' => (int) config('auction.deadlines.review_sla_hours'),
            ],
            'bidding' => [
                'rate_limit_per_minute' => (int) config('auction.bidding.rate_limit_per_minute'),
                'rate_limit_per_minute_per_ip' => (int) config('auction.bidding.rate_limit_per_minute_per_ip'),
            ],
            'source' => 'environment',
        ], __('auction.messages.operational_settings_fetched'));
    }
}

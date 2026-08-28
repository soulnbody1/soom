<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction\Auction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'إعدادات المزادات', description: 'إصدارات إعدادات المزادات المالية والزمنية والإعدادات التشغيلية المشتقة من بيئة التشغيل.', weight: 14)]
final class AuctionOperationalSettingsController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض الإعدادات التشغيلية للمزادات',
        description: 'يعرض المهل الزمنية ومواعيد التذكير وحدود معدل المزايدة المعتمدة حاليًا. هذه القيم تُقرأ من بيئة التشغيل ولا تُعدَّل عبر الواجهة البرمجية.'
    )]
    #[Response(200, description: 'المهل الزمنية وحدود المزايدة السارية.')]
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

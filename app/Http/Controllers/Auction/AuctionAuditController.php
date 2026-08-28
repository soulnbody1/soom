<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionStatusHistory;
use App\Repositories\Auction\AuctionAuditRepository;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'سجل المزاد', description: 'سجلات النشاط وتحولات الحالة الخاصة بمزاد واحد.', weight: 15)]
final class AuctionAuditController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض سجل نشاط المزاد',
        description: 'يعرض أحداث المزاد مرتبةً من الأحدث إلى الأقدم مع نوع الحدث والجهة المنفّذة والبيانات المرافقة لكل حدث.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[Response(200, description: 'أحداث المزاد مقسّمة إلى صفحات.')]
    public function activity(Request $request, Auction $auction, AuctionAuditRepository $audit): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $audit
            ->paginateActivityForAuction($auction, min(100, max(1, (int) $request->input('per_page', 20))))
            ->through(fn (AuctionActivityLog $entry): array => [
                'id' => $entry->public_id,
                'event_type' => $entry->event_type,
                'actor_type' => $entry->actor_type,
                'user' => $entry->relationLoaded('user') && $entry->user ? [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                ] : null,
                'metadata' => $entry->metadata,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return $this->sendResponse($paginator, __('auction.messages.activity_fetched'));
    }

    #[Endpoint(
        title: 'عرض سجل تحوّلات حالة المزاد',
        description: 'يعرض تسلسل تحوّلات حالة المزاد منذ إنشائه مع الحالة السابقة والحالة الجديدة والجهة التي نفّذت التحوّل وسببه.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'قائمة تحوّلات الحالة مرتبةً زمنيًا.')]
    public function statusHistory(Auction $auction, AuctionAuditRepository $audit): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $history = $audit->statusHistoryForAuction($auction)
            ->map(fn (AuctionStatusHistory $entry): array => [
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'actor_type' => $entry->actor_type,
                'changed_by' => $entry->changed_by,
                'actor' => $entry->relationLoaded('changedBy') && $entry->changedBy ? [
                    'id' => $entry->changedBy->id,
                    'name' => $entry->changedBy->name,
                ] : null,
                'reason' => $entry->reason,
                'metadata' => $entry->metadata,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])
            ->values();

        return $this->sendResponse($history, __('auction.messages.status_history_fetched'));
    }
}

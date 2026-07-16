<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction\AuctionDispute;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AuctionDisputeController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, AuctionDisputeRepository $disputes): JsonResponse
    {
        Gate::authorize('viewAny', AuctionDispute::class);

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(['open', 'resolved'])],
            'auction_id' => ['nullable', 'string', 'max:40'],
        ]);

        $paginator = $disputes
            ->paginateForAdmin($filters, min(100, max(1, (int) $request->input('per_page', 20))))
            ->through(fn (AuctionDispute $dispute): array => [
                'id' => $dispute->public_id,
                'status' => $dispute->status,
                'reason' => $dispute->reason,
                'opened_at' => $dispute->opened_at?->toIso8601String(),
                'resolved_at' => $dispute->resolved_at?->toIso8601String(),
                'resolution_note' => $dispute->resolution_note,
                'opened_by' => $dispute->opener ? [
                    'id' => $dispute->opener->id,
                    'name' => $dispute->opener->name,
                ] : null,
                'resolved_by' => $dispute->resolver ? [
                    'id' => $dispute->resolver->id,
                    'name' => $dispute->resolver->name,
                ] : null,
                'auction' => $dispute->auction ? [
                    'id' => $dispute->auction->public_id,
                    'title' => $dispute->auction->title,
                    'status' => $dispute->auction->status->value,
                ] : null,
                'settlement_id' => $dispute->settlement?->public_id,
            ]);

        return $this->sendResponse($paginator, __('auction.messages.disputes_fetched'));
    }
}

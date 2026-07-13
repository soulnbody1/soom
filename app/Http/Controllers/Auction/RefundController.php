<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\MoneyResource;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Actions\CancelAuctionRefundAction;
use App\Services\Auction\Actions\ConfirmAuctionRefundManuallyAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RefundController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, AuctionRefundRepository $refunds): JsonResponse
    {
        Gate::authorize('viewAny', RefundTransaction::class);

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($status) => $status->value, RefundTransactionStatus::cases()))],
            'auction_id' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $refunds
            ->paginateForAdmin($filters, min(100, max(1, (int) $request->input('per_page', 20))))
            ->through(fn (RefundTransaction $refund): array => $this->refundPayload($refund));

        return $this->sendResponse($paginator, __('auction.messages.refunds_fetched'));
    }

    public function confirm(
        Request $request,
        RefundTransaction $refund,
        ConfirmAuctionRefundManuallyAction $action
    ): JsonResponse {
        Gate::authorize('confirmManual', $refund);

        $data = $request->validate([
            'confirmation_reference' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $confirmed = $action->execute(
            $refund,
            $request->user(),
            (string) $data['confirmation_reference'],
            (string) $data['reason']
        );

        return $this->sendResponse($this->refundPayload($confirmed), __('auction.messages.refund_confirmed'));
    }

    public function cancel(
        Request $request,
        RefundTransaction $refund,
        CancelAuctionRefundAction $action
    ): JsonResponse {
        Gate::authorize('cancel', $refund);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $cancelled = $action->execute($refund, $request->user(), (string) $data['reason']);

        return $this->sendResponse($this->refundPayload($cancelled), __('auction.messages.refund_cancelled'));
    }

    private function refundPayload(RefundTransaction $refund): array
    {
        $refund->loadMissing([
            'auction:id,public_id,title',
            'user:id,name',
        ]);

        return [
            'public_id' => $refund->public_id,
            'auction' => $refund->auction ? [
                'id' => $refund->auction->public_id,
                'title' => $refund->auction->title,
            ] : null,
            'owner' => $refund->user ? [
                'id' => $refund->user->id,
                'name' => $refund->user->name,
            ] : null,
            'amount' => MoneyResource::make((int) $refund->amount_minor, (string) $refund->currency_code),
            'currency' => $refund->currency_code,
            'status' => $refund->status->value,
            'reason' => $refund->reason,
            'attempt_count' => (int) $refund->attempt_count,
            'last_error' => $refund->last_error,
            'created_at' => $refund->created_at?->toIso8601String(),
            'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
        ];
    }
}

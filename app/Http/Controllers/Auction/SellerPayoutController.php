<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AdminSellerPayoutIndexRequest;
use App\Http\Requests\Auction\FailSellerPayoutRequest;
use App\Http\Requests\Auction\MarkSellerPayoutPaidRequest;
use App\Http\Resources\Auction\MoneyResource;
use App\Http\Resources\Auction\SellerPayoutResource;
use App\Models\Auction\AuctionSellerPayout;
use App\Repositories\Auction\AuctionSellerPayoutRepository;
use App\Services\Auction\Actions\FailSellerPayoutAction;
use App\Services\Auction\Actions\HoldSellerPayoutAction;
use App\Services\Auction\Actions\MarkSellerPayoutPaidAction;
use App\Services\Auction\Actions\StartSellerPayoutProcessingAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class SellerPayoutController extends Controller
{
    use ApiResponseTrait;

    private const ADMIN_RELATIONS = ['auction:id,public_id,title', 'seller:id,name,phone', 'settlement', 'processor:id,name', 'payer:id,name'];

    public function index(AdminSellerPayoutIndexRequest $request, AuctionSellerPayoutRepository $payouts): JsonResponse
    {
        Gate::authorize('viewAny', AuctionSellerPayout::class);

        $paginator = $payouts
            ->paginateForAdmin($request->filters(), $request->perPage())
            ->through(fn (AuctionSellerPayout $payout) => new SellerPayoutResource($payout));

        return $this->sendResponse($paginator, __('auction.messages.payouts_fetched'));
    }

    public function summary(AuctionSellerPayoutRepository $payouts): JsonResponse
    {
        Gate::authorize('viewAny', AuctionSellerPayout::class);

        $summary = [];
        foreach ($payouts->summaryByStatus() as $status => $row) {
            $summary[$status] = [
                'count' => $row['count'],
                'amount' => MoneyResource::make($row['amount_minor'], $row['currency_code']),
            ];
        }

        return $this->sendResponse($summary, __('auction.messages.payouts_fetched'));
    }

    public function show(AuctionSellerPayout $sellerPayout, AuctionSellerPayoutRepository $payouts): JsonResponse
    {
        Gate::authorize('view', $sellerPayout);

        $sellerPayout->load(self::ADMIN_RELATIONS);
        $payload = (new SellerPayoutResource($sellerPayout))->resolve();
        $payload['seller_deposit_refund'] = $this->sellerDepositRefund($sellerPayout, $payouts);

        return $this->sendResponse($payload, __('auction.messages.payout_fetched'));
    }

    public function startProcessing(Request $request, AuctionSellerPayout $sellerPayout, StartSellerPayoutProcessingAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        return $this->transitionResponse(
            fn () => $action->execute($sellerPayout, $request->user()),
            __('auction.messages.payout_processing_started')
        );
    }

    public function markPaid(MarkSellerPayoutPaidRequest $request, AuctionSellerPayout $sellerPayout, MarkSellerPayoutPaidAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        return $this->transitionResponse(
            fn () => $action->execute(
                $sellerPayout,
                $request->user(),
                (string) $request->validated('payout_method'),
                (string) $request->validated('transfer_reference'),
                $request->file('proof'),
                $request->validated('note'),
                $request->destinationOverride(),
            ),
            __('auction.messages.payout_paid')
        );
    }

    public function markFailed(FailSellerPayoutRequest $request, AuctionSellerPayout $sellerPayout, FailSellerPayoutAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        return $this->transitionResponse(
            fn () => $action->execute(
                $sellerPayout,
                $request->user(),
                SellerPayoutStatus::from((string) $request->validated('status')),
                (string) $request->validated('reason'),
            ),
            __('auction.messages.payout_failure_recorded')
        );
    }

    public function hold(Request $request, AuctionSellerPayout $sellerPayout, HoldSellerPayoutAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->transitionResponse(
            fn () => $action->hold($sellerPayout, $request->user(), (string) $data['reason']),
            __('auction.messages.payout_held')
        );
    }

    public function release(Request $request, AuctionSellerPayout $sellerPayout, HoldSellerPayoutAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        return $this->transitionResponse(
            fn () => $action->release($sellerPayout, $request->user()),
            __('auction.messages.payout_released')
        );
    }

    public function proofUrl(AuctionSellerPayout $sellerPayout): JsonResponse
    {
        Gate::authorize('view', $sellerPayout);

        if ($sellerPayout->proof_path === null) {
            return $this->sendError(__('auction.errors.payout_proof_unavailable'), 404, 'payout_proof_unavailable');
        }

        try {
            $url = Storage::disk((string) $sellerPayout->proof_disk)
                ->temporaryUrl($sellerPayout->proof_path, now()->addMinutes(10));
        } catch (\Throwable) {
            return $this->sendError(__('auction.errors.payout_proof_unavailable'), 404, 'payout_proof_unavailable');
        }

        return $this->sendResponse([
            'url' => $url,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], __('auction.messages.payout_proof_url_created'));
    }

    public function mine(Request $request, AuctionSellerPayoutRepository $payouts): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $payouts
            ->paginateForSeller((int) $request->user()->id, $filters, min(100, max(1, (int) $request->input('per_page', 20))))
            ->through(fn (AuctionSellerPayout $payout) => SellerPayoutResource::sellerPayload($payout));

        return $this->sendResponse($paginator, __('auction.messages.payouts_fetched'));
    }

    public function showMine(Request $request, AuctionSellerPayout $sellerPayout): JsonResponse
    {
        if ($request->user()->id !== (int) $sellerPayout->seller_id) {
            return $this->sendError(__('auction.errors.payout_not_found'), 404, 'payout_not_found');
        }

        $sellerPayout->load('auction:id,public_id,title');

        return $this->sendResponse(SellerPayoutResource::sellerPayload($sellerPayout), __('auction.messages.payout_fetched'));
    }

    private function transitionResponse(callable $callback, string $message): JsonResponse
    {
        $payout = $callback();

        $payout->load(self::ADMIN_RELATIONS);

        return $this->sendResponse(new SellerPayoutResource($payout), $message);
    }

    private function sellerDepositRefund(AuctionSellerPayout $payout, AuctionSellerPayoutRepository $payouts): ?array
    {
        $refund = $payouts->sellerDepositRefund($payout);

        return $refund ? [
            'id' => $refund->public_id,
            'status' => $refund->status->value,
            'amount' => MoneyResource::make((int) $refund->amount_minor, (string) $refund->currency_code),
            'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
        ] : null;
    }
}

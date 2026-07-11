<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Application\Auction\Actions\AcceptAuctionTermsAction;
use App\Application\Auction\Actions\CompleteHandoverAction;
use App\Application\Auction\Actions\CreateAuctionAction;
use App\Application\Auction\Actions\RegisterParticipantAction;
use App\Application\Auction\Actions\ReviewAuctionAction;
use App\Application\Auction\Actions\SubmitAuctionForReviewAction;
use App\Application\Auction\Actions\SubmitPaymentSubmissionAction;
use App\Application\Auction\Services\AuctionMetricsRecorder;
use App\Application\Auction\Services\AuctionStateMachine;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\PaymentSubmissionRequest;
use App\Http\Requests\Auction\ReviewAuctionRequest;
use App\Http\Requests\Auction\StoreAuctionRequest;
use App\Http\Resources\Auction\AuctionResource;
use App\Http\Resources\Auction\AuctionSummaryResource;
use App\Http\Resources\Auction\AuctionParticipantResource;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Models\Auction\Auction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuctionController extends Controller
{
    use ApiResponseTrait;

    public function index(AuctionIndexRequest $request): JsonResponse
    {
        $query = Auction::query()
            ->public()
            ->with(['media', 'category', 'metric', 'currentLeadingBid'])
            ->latest('id');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return $this->sendResponse(
            AuctionSummaryResource::collection($query->paginate($request->perPage())),
            'Auctions fetched.'
        );
    }

    public function all(AuctionIndexRequest $request): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return $this->sendError('Forbidden.', 403);
        }

        return $this->sendResponse(
            AuctionResource::collection(
                Auction::with(['media', 'category', 'seller', 'metric', 'currentLeadingBid', 'winningBid', 'settlement'])
                    ->latest('id')
                    ->paginate($request->perPage())
            ),
            'Admin auctions fetched.'
        );
    }

    public function show(Request $request, Auction $auction, AuctionMetricsRecorder $metrics): JsonResponse
    {
        if (! $auction->status->isPubliclyVisible() && $request->user()?->id !== $auction->seller_id && $request->user()?->role !== 'admin') {
            return $this->sendError('Auction not found.', 404);
        }

        $metrics->recordView($auction, $request->user()?->id, $request->ip(), $request->userAgent());

        return $this->sendResponse(
            new AuctionResource($auction->load([
                'media',
                'category',
                'country',
                'state',
                'city',
                'metric',
                'currentLeadingBid.bidder',
                'winningBid.bidder',
                'settlement',
            ])),
            'Auction fetched.'
        );
    }

    public function store(StoreAuctionRequest $request, CreateAuctionAction $action): JsonResponse
    {
        try {
            $auction = $action->execute($request->validated(), Auth::id());

            return $this->sendResponse(new AuctionResource($auction), 'Auction created.', 201);
        } catch (\Throwable $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function submitForReview(Auction $auction, SubmitAuctionForReviewAction $action): JsonResponse
    {
        try {
            return $this->sendResponse(
                new AuctionResource($action->execute($auction, Auth::id())),
                'Auction submitted for review.'
            );
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function review(ReviewAuctionRequest $request, Auction $auction, ReviewAuctionAction $action): JsonResponse
    {
        $data = $request->validated();
        $reviewed = $data['action'] === 'approve'
            ? $action->approve($auction, Auth::id(), $data['reason'])
            : $action->reject($auction, Auth::id(), $data['reason']);

        return $this->sendResponse(new AuctionResource($reviewed), 'Auction reviewed.');
    }

    public function cancel(Request $request, Auction $auction, AuctionTransaction $transaction, AuctionStateMachine $stateMachine): JsonResponse
    {
        $actor = $request->user();
        if ($actor?->role !== 'admin' && $actor?->id !== $auction->seller_id) {
            return $this->sendError('Forbidden.', 403);
        }

        $cancelled = $transaction->run(fn () => $stateMachine->transition(
            Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail(),
            AuctionStatus::Cancelled,
            $actor?->id,
            $actor?->role === 'admin' ? 'admin' : 'user',
            (string) $request->input('reason', 'cancelled by actor')
        ));

        return $this->sendResponse(new AuctionResource($cancelled), 'Auction cancelled.');
    }

    public function register(Auction $auction, RegisterParticipantAction $action): JsonResponse
    {
        try {
            return $this->sendResponse(
                new AuctionParticipantResource($action->execute($auction, Auth::id())),
                'Participant registered.',
                201
            );
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function acceptTerms(Request $request, Auction $auction, AcceptAuctionTermsAction $action): JsonResponse
    {
        try {
            $acceptance = $action->execute($auction, Auth::id(), $request->ip(), $request->userAgent());

            return $this->sendResponse(['id' => $acceptance->public_id], 'Auction terms accepted.', 201);
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function submitSellerDeposit(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action
    ): JsonResponse {
        return $this->submitPayment($request, $auction, $action, PaymentPurpose::SellerDeposit);
    }

    public function submitBidderDeposit(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action
    ): JsonResponse {
        return $this->submitPayment($request, $auction, $action, PaymentPurpose::BidderDeposit);
    }

    public function submitWinnerPayment(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action
    ): JsonResponse {
        return $this->submitPayment($request, $auction, $action, PaymentPurpose::WinnerSettlement);
    }

    public function completeHandover(Auction $auction, CompleteHandoverAction $action): JsonResponse
    {
        try {
            return $this->sendResponse(
                new AuctionResource($action->execute($auction, Auth::id())->load('settlement')),
                'Auction completed.'
            );
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function mine(AuctionIndexRequest $request): JsonResponse
    {
        return $this->sendResponse(
            AuctionResource::collection(
                Auction::with(['media', 'metric', 'currentLeadingBid', 'settlement'])
                    ->where('seller_id', Auth::id())
                    ->latest('id')
                    ->paginate($request->perPage())
            ),
            'Seller auctions fetched.'
        );
    }

    private function submitPayment(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action,
        PaymentPurpose $purpose
    ): JsonResponse {
        try {
            $data = $request->validated();
            $submission = $action->execute(
                $auction,
                Auth::id(),
                $purpose,
                (int) $data['payment_method_id'],
                $request->file('receipt'),
                $data['idempotency_key'],
                $data['provider_reference'] ?? null
            );

            return $this->sendResponse(new PaymentSubmissionResource($submission), 'Payment submitted.', 201);
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }
}

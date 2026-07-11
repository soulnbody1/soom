<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\CreateAuctionInputDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\MarkWinnerDefaultedRequest;
use App\Http\Requests\Auction\OpenAuctionDisputeRequest;
use App\Http\Requests\Auction\PaymentSubmissionRequest;
use App\Http\Requests\Auction\ResolveAuctionDisputeRequest;
use App\Http\Requests\Auction\ReviewAuctionRequest;
use App\Http\Requests\Auction\StoreAuctionRequest;
use App\Http\Resources\Auction\AdminAuctionResource;
use App\Http\Resources\Auction\AuctionParticipantResource;
use App\Http\Resources\Auction\AuctionResource;
use App\Http\Resources\Auction\AuctionSummaryResource;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Http\Resources\Auction\PublicAuctionResource;
use App\Http\Resources\Auction\SellerAuctionResource;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDispute;
use App\Services\Auction\Actions\AcceptAuctionTermsAction;
use App\Services\Auction\Actions\CancelAuctionAction;
use App\Services\Auction\Actions\ConfirmAuctionHandoverBySellerAction;
use App\Services\Auction\Actions\ConfirmAuctionReceiptByWinnerAction;
use App\Services\Auction\Actions\CreateAuctionAction;
use App\Services\Auction\Actions\ListAdminAuctionsAction;
use App\Services\Auction\Actions\ListPublicAuctionsAction;
use App\Services\Auction\Actions\ListSellerAuctionsAction;
use App\Services\Auction\Actions\LoadAuctionDetailsAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use App\Services\Auction\Actions\OpenAuctionDisputeAction;
use App\Services\Auction\Actions\RegisterParticipantAction;
use App\Services\Auction\Actions\ResolveAuctionDisputeAction;
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\Auction\Actions\SubmitAuctionForReviewAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use App\Services\Auction\Support\AuctionMetricsRecorder;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class AuctionController extends Controller
{
    use ApiResponseTrait;

    public function index(AuctionIndexRequest $request, ListPublicAuctionsAction $action): JsonResponse
    {
        return $this->sendResponse(
            PublicAuctionResource::collection($action->execute(
                $request->filled('category_id') ? $request->integer('category_id') : null,
                $request->perPage()
            )),
            __('auction.messages.auctions_fetched')
        );
    }

    public function all(AuctionIndexRequest $request, ListAdminAuctionsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse(
            AdminAuctionResource::collection($action->execute($request->perPage())),
            __('auction.messages.admin_auctions_fetched')
        );
    }

    public function show(
        Request $request,
        Auction $auction,
        AuctionMetricsRecorder $metrics,
        LoadAuctionDetailsAction $action
    ): JsonResponse {
        if (! Gate::allows('view', $auction)) {
            return $this->sendError(__('auction.errors.auction_not_found'), 404);
        }

        $metrics->recordView($auction, $request->user()?->id, $request->ip(), $request->userAgent());
        $loaded = $action->execute($auction);
        $user = $request->user();

        // Choose resource based on viewer role
        $resource = match (true) {
            $user?->role === 'admin' => new AdminAuctionResource($loaded),
            $user?->id === $auction->seller_id => new SellerAuctionResource($loaded),
            default => new PublicAuctionResource($loaded),
        };

        return $this->sendResponse($resource, __('auction.messages.auction_fetched'));
    }

    public function store(StoreAuctionRequest $request, CreateAuctionAction $action): JsonResponse
    {
        Gate::authorize('create', Auction::class);

        $input = CreateAuctionInputDTO::fromValidated($request->validated());
        $auction = $action->execute($input, Auth::id());

        return $this->sendResponse(new SellerAuctionResource($auction), __('auction.messages.auction_created'), 201);
    }

    public function submitForReview(Auction $auction, SubmitAuctionForReviewAction $action): JsonResponse
    {
        try {
            Gate::authorize('submitForReview', $auction);

            return $this->sendResponse(
                new AuctionResource($action->execute($auction, Auth::id())),
                __('auction.messages.auction_submitted')
            );
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function review(ReviewAuctionRequest $request, Auction $auction, ReviewAuctionAction $action): JsonResponse
    {
        $data = $request->validated();
        Gate::authorize($data['action'] === 'approve' ? 'approve' : 'review', $auction);

        $reviewed = $data['action'] === 'approve'
            ? $action->approve($auction, Auth::id(), $data['reason'])
            : $action->reject($auction, Auth::id(), $data['reason']);

        return $this->sendResponse(new AuctionResource($reviewed), __('auction.messages.auction_reviewed'));
    }

    public function cancel(Request $request, Auction $auction, CancelAuctionAction $action): JsonResponse
    {
        $actor = $request->user();
        Gate::authorize('cancel', $auction);

        $cancelled = $action->execute(
            $auction,
            $actor->id,
            $actor->role === 'admin' ? 'admin' : 'user',
            (string) $request->input('reason', __('auction.audit.cancelled_by_actor'))
        );

        return $this->sendResponse(new AuctionResource($cancelled), __('auction.messages.auction_cancelled'));
    }

    public function register(Auction $auction, RegisterParticipantAction $action): JsonResponse
    {
        try {
            Gate::authorize('register', $auction);

            return $this->sendResponse(
                new AuctionParticipantResource($action->execute($auction, Auth::id())),
                __('auction.messages.participant_registered'),
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

            return $this->sendResponse(['id' => $acceptance->public_id], __('auction.messages.terms_accepted'), 201);
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

    public function confirmSellerHandover(Auction $auction, ConfirmAuctionHandoverBySellerAction $action): JsonResponse
    {
        try {
            Gate::authorize('confirmSellerHandover', $auction);

            return $this->sendResponse(
                new AuctionResource($action->execute($auction, Auth::id())->load('settlement')),
                __('auction.messages.seller_handover_confirmed')
            );
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function confirmWinnerReceipt(Auction $auction, ConfirmAuctionReceiptByWinnerAction $action): JsonResponse
    {
        try {
            Gate::authorize('confirmWinnerReceipt', $auction);

            return $this->sendResponse(
                new AuctionResource($action->execute($auction, Auth::id())->load('settlement')),
                __('auction.messages.winner_receipt_confirmed')
            );
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function openDispute(OpenAuctionDisputeRequest $request, Auction $auction, OpenAuctionDisputeAction $action): JsonResponse
    {
        Gate::authorize('openDispute', $auction);

        $dispute = $action->execute($auction, Auth::id(), (string) $request->validated('reason'));

        return $this->sendResponse([
            'id' => $dispute->public_id,
            'status' => $dispute->status,
        ], __('auction.messages.dispute_opened'), 201);
    }

    public function resolveDispute(
        ResolveAuctionDisputeRequest $request,
        Auction $auction,
        AuctionDispute $auctionDispute,
        ResolveAuctionDisputeAction $action
    ): JsonResponse {
        Gate::authorize('resolveDispute', $auction);

        $resolved = $action->execute(
            $auction,
            $auctionDispute,
            Auth::id(),
            (string) $request->validated('resolution'),
            (string) $request->validated('note')
        );

        return $this->sendResponse(new AuctionResource($resolved), __('auction.messages.dispute_resolved'));
    }

    public function markWinnerDefaulted(
        MarkWinnerDefaultedRequest $request,
        Auction $auction,
        MarkWinnerDefaultedAction $action
    ): JsonResponse {
        Gate::authorize('resolveDispute', $auction);

        $updated = $action->execute(
            $auction,
            Auth::id(),
            (string) $request->validated('reason'),
            (bool) $request->boolean('reassign_to_next')
        );

        return $this->sendResponse(
            new AuctionResource($updated->load('settlement')),
            __('auction.messages.winner_default_processed')
        );
    }

    public function mine(AuctionIndexRequest $request, ListSellerAuctionsAction $action): JsonResponse
    {
        return $this->sendResponse(
            SellerAuctionResource::collection($action->execute(Auth::id(), $request->perPage())),
            __('auction.messages.seller_auctions_fetched')
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
                $data['payment_method_id'],
                $request->file('receipt'),
                $data['idempotency_key'],
                $data['provider_reference'] ?? null
            );

            return $this->sendResponse(new PaymentSubmissionResource($submission), __('auction.messages.payment_submitted'), 201);
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }
}

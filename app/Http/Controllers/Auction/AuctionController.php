<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\CreateAuctionInputDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\CancelAuctionRequest;
use App\Http\Requests\Auction\MarkWinnerDefaultedRequest;
use App\Http\Requests\Auction\OpenAuctionDisputeRequest;
use App\Http\Requests\Auction\PaymentSubmissionRequest;
use App\Http\Requests\Auction\ResolveAuctionDisputeRequest;
use App\Http\Requests\Auction\ReviewAuctionRequest;
use App\Http\Requests\Auction\StoreAuctionRequest;
use App\Http\Resources\Auction\AdminAuctionResource;
use App\Http\Resources\Auction\AuctionParticipantResource;
use App\Http\Resources\Auction\MyAuctionResource;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Http\Resources\Auction\PublicAuctionResource;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\PaymentSubmission;
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

        if ($user && Gate::forUser($user)->allows('viewAny', Auction::class)) {
            $this->loadAdminAuctionRelations($loaded, $user);
            $resource = new AdminAuctionResource($loaded);
        } elseif ($user) {
            $this->loadMyAuctionRelations($loaded, $user->id);
            $resource = $this->hasMyAuctionData($loaded, $user->id)
                ? new MyAuctionResource($loaded)
                : new PublicAuctionResource($loaded);
        } else {
            $resource = new PublicAuctionResource($loaded);
        }

        return $this->sendResponse($resource, __('auction.messages.auction_fetched'));
    }

    public function store(StoreAuctionRequest $request, CreateAuctionAction $action): JsonResponse
    {
        Gate::authorize('create', Auction::class);

        $input = CreateAuctionInputDTO::fromValidated($request->validated());
        $auction = $action->execute($input, Auth::id());

        $this->loadMyAuctionRelations($auction, Auth::id());

        return $this->sendResponse(new MyAuctionResource($auction), __('auction.messages.auction_created'), 201);
    }

    public function submitForReview(Auction $auction, SubmitAuctionForReviewAction $action): JsonResponse
    {
        try {
            Gate::authorize('submitForReview', $auction);

            return $this->auctionResponse($action->execute($auction, Auth::id()), __('auction.messages.auction_submitted'));
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

        return $this->auctionResponse($reviewed, __('auction.messages.auction_reviewed'));
    }

    public function cancel(CancelAuctionRequest $request, Auction $auction, CancelAuctionAction $action): JsonResponse
    {
        $actor = $request->user();
        Gate::authorize('cancel', $auction);

        $cancelled = $action->execute(
            $auction,
            $actor->id,
            $actor->role === 'admin' ? 'admin' : 'user',
            $request->reasonText(),
            $request->reasonCode(),
            $request->liability()
        );

        return $this->auctionResponse($cancelled, __('auction.messages.auction_cancelled'));
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

            return $this->auctionResponse(
                $action->execute($auction, Auth::id()),
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

            return $this->auctionResponse(
                $action->execute($auction, Auth::id()),
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
            (string) $request->validated('note'),
            $request->validated('seller_deposit_disposition'),
            $request->validated('seller_deposit_forfeit_amount_minor')
        );

        return $this->auctionResponse($resolved, __('auction.messages.dispute_resolved'));
    }

    public function markWinnerDefaulted(
        MarkWinnerDefaultedRequest $request,
        Auction $auction,
        MarkWinnerDefaultedAction $action
    ): JsonResponse {
        Gate::authorize('markWinnerDefaulted', $auction);

        $updated = $action->execute(
            $auction,
            Auth::id(),
            (string) $request->validated('reason'),
            (bool) $request->boolean('reassign_to_next'),
            (bool) $request->boolean('override_deadline'),
            (string) ($request->validated('override_reason') ?? '')
        );

        return $this->auctionResponse($updated, __('auction.messages.winner_default_processed'));
    }

    public function mine(AuctionIndexRequest $request, ListSellerAuctionsAction $action): JsonResponse
    {
        return $this->sendResponse(
            MyAuctionResource::collection($action->execute(Auth::id(), $request->perPage())),
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

    private function auctionResponse(Auction $auction, string $message, int $status = 200): JsonResponse
    {
        $auction->load([
            'media',
            'category',
            'country',
            'state',
            'city',
            'metric',
            'currentLeadingBid',
        ]);

        $user = request()->user();

        if ($user && Gate::forUser($user)->allows('viewAny', Auction::class)) {
            $this->loadAdminAuctionRelations($auction, $user);

            return $this->sendResponse(new AdminAuctionResource($auction), $message, $status);
        }

        if ($user) {
            $this->loadMyAuctionRelations($auction, $user->id);

            return $this->sendResponse(new MyAuctionResource($auction), $message, $status);
        }

        return $this->sendResponse(new PublicAuctionResource($auction), $message, $status);
    }

    private function loadMyAuctionRelations(Auction $auction, int $userId): void
    {
        $isSeller = $auction->seller_id === $userId;

        $auction->load([
            'bids' => fn ($query) => $query
                ->where('bidder_id', $userId)
                ->orderBy('sequence_number'),
            'deposits' => fn ($query) => $query
                ->where('user_id', $userId)
                ->with([
                    'paymentSubmissions.paymentMethod',
                    'paymentSubmissions.transaction.refunds' => fn ($refunds) => $refunds->where('user_id', $userId),
                ]),
            'sellerDeposit' => fn ($query) => $query
                ->when(! $isSeller, fn ($sellerDeposit) => $sellerDeposit->whereRaw('1 = 0'))
                ->with([
                    'paymentSubmissions.paymentMethod',
                    'paymentSubmissions.transaction.refunds' => fn ($refunds) => $refunds->where('user_id', $userId),
                ]),
            'settlement' => fn ($query) => $isSeller
                ? $query
                : $query->where('winner_id', $userId),
        ]);
    }

    private function loadAdminAuctionRelations(Auction $auction, object $user): void
    {
        $relations = [
            'seller',
            'winningBid',
            'settlement',
        ];

        if (Gate::forUser($user)->allows('viewAny', PaymentSubmission::class)) {
            $relations[] = 'deposits.paymentSubmissions.paymentMethod';
            $relations[] = 'deposits.paymentSubmissions.transaction.refunds';
        }

        if (Gate::forUser($user)->allows('resolveDispute', $auction)) {
            $relations[] = 'disputes';
        }

        $auction->load($relations);
    }

    private function hasMyAuctionData(Auction $auction, int $userId): bool
    {
        return $auction->seller_id === $userId
            || ($auction->relationLoaded('bids') && $auction->bids->isNotEmpty())
            || ($auction->relationLoaded('deposits') && $auction->deposits->isNotEmpty())
            || ($auction->relationLoaded('settlement') && $auction->settlement?->winner_id === $userId);
    }
}

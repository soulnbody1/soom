<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\DTO\Auction\CreateAuctionInputDTO;
use App\DTO\Auction\UpdateDraftAuctionInputDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AdminAuctionIndexRequest;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\CancelAuctionRequest;
use App\Http\Requests\Auction\MarkWinnerDefaultedRequest;
use App\Http\Requests\Auction\OpenAuctionDisputeRequest;
use App\Http\Requests\Auction\PaymentSubmissionRequest;
use App\Http\Requests\Auction\ResolveAuctionDisputeRequest;
use App\Http\Requests\Auction\ReviewAuctionRequest;
use App\Http\Requests\Auction\StoreAuctionRequest;
use App\Http\Requests\Auction\UpdateDraftAuctionRequest;
use App\Http\Resources\Auction\AdminAuctionResource;
use App\Http\Resources\Auction\AuctionParticipantResource;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Http\Resources\Auction\UserAuctionResource;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\PaymentSubmission;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Services\Auction\Actions\AcceptAuctionTermsAction;
use App\Services\Auction\Actions\BlockAuctionParticipantAction;
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
use App\Services\Auction\Actions\ReopenRejectedAuctionAction;
use App\Services\Auction\Actions\ResolveAuctionDisputeAction;
use App\Services\Auction\Actions\SubmitAuctionForReviewAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use App\Services\Auction\Actions\UpdateDraftAuctionAction;
use App\Services\Auction\Support\AuctionMetricsRecorder;
use App\Services\Auction\Support\ParticipationStateResolver;
use App\Services\ContentReview\Actions\ApplyContentReviewDecisionAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AuctionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly ParticipationStateResolver $participation,
    ) {}

    public function index(AuctionIndexRequest $request, ListPublicAuctionsAction $action): JsonResponse
    {
        $paginator = $action->execute($request->filters(), $request->user()?->id, $request->perPage());
        $this->participation->forCollection($paginator->items(), $request->user());

        return $this->sendResponse(
            UserAuctionResource::collection($paginator),
            __('auction.messages.auctions_fetched')
        );
    }

    public function all(AdminAuctionIndexRequest $request, ListAdminAuctionsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse(
            AdminAuctionResource::collection($action->execute(
                $request->filters(),
                $request->perPage(),
                true
            )),
            __('auction.messages.admin_auctions_fetched')
        );
    }

    public function participants(
        Request $request,
        Auction $auction,
        AuctionParticipantRepository $participants
    ): JsonResponse {
        Gate::authorize('viewAny', Auction::class);

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(AuctionParticipantStatus::cases(), 'value'))],
        ]);

        return $this->sendResponse(
            AuctionParticipantResource::collection($participants->paginateByAuction(
                $auction,
                $filters['status'] ?? null,
                min(100, max(1, (int) $request->input('per_page', 20)))
            )),
            __('auction.messages.participants_fetched')
        );
    }

    public function show(
        Request $request,
        Auction $auction,
        AuctionMetricsRecorder $metrics,
        LoadAuctionDetailsAction $action
    ): JsonResponse {
        if (! Gate::allows('view', $auction)) {
            return $this->sendError(__('auction.errors.auction_not_found'), 404, 'auction_not_found');
        }

        $metrics->recordView($auction, $request->user()?->id, $request->ip(), $request->userAgent());

        return $this->sendResponse(
            $this->userAuctionResource($auction, $request->user(), $action),
            __('auction.messages.auction_fetched')
        );
    }

    public function showForAdmin(Request $request, Auction $auction, LoadAuctionDetailsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $loaded = $action->execute($auction, $request->user()?->id);
        $this->loadAdminAuctionRelations($loaded, $request->user());

        return $this->sendResponse(new AdminAuctionResource($loaded), __('auction.messages.auction_fetched'));
    }

    public function store(StoreAuctionRequest $request, CreateAuctionAction $action, LoadAuctionDetailsAction $details): JsonResponse
    {
        Gate::authorize('create', Auction::class);

        $input = CreateAuctionInputDTO::fromValidated($request->validated());
        $auction = $action->execute($input, Auth::id());

        return $this->sendResponse(
            $this->userAuctionResource($auction, $request->user(), $details),
            __('auction.messages.auction_created'),
            201
        );
    }

    public function update(UpdateDraftAuctionRequest $request, Auction $auction, UpdateDraftAuctionAction $action): JsonResponse
    {
        Gate::authorize('update', $auction);

        $input = UpdateDraftAuctionInputDTO::fromValidated($request->validated());

        return $this->auctionResponse($action->execute($auction, $input, Auth::id()), __('auction.messages.auction_updated'));
    }

    public function reopen(Auction $auction, ReopenRejectedAuctionAction $action): JsonResponse
    {
        Gate::authorize('reopen', $auction);

        return $this->auctionResponse($action->execute($auction, Auth::id()), __('auction.messages.auction_reopened'));
    }

    public function submitForReview(Auction $auction, SubmitAuctionForReviewAction $action): JsonResponse
    {
        Gate::authorize('submitForReview', $auction);

        return $this->auctionResponse($action->execute($auction, Auth::id()), __('auction.messages.auction_submitted'));
    }

    public function review(
        ReviewAuctionRequest $request,
        Auction $auction,
        ApplyContentReviewDecisionAction $action
    ): JsonResponse {
        $data = $request->validated();
        Gate::authorize($data['action'] === 'approve' ? 'approve' : 'review', $auction);

        $action->applyHumanDecision(
            ReviewableSubjectType::Auction,
            (int) $auction->id,
            $data['action'] === 'approve'
                ? ContentReviewDecisionType::Approved
                : ContentReviewDecisionType::Rejected,
            $request->user(),
            (string) $data['reason'],
        );

        return $this->auctionResponse($auction->refresh(), __('auction.messages.auction_reviewed'));
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
        Gate::authorize('register', $auction);

        return $this->sendResponse(
            new AuctionParticipantResource($action->execute($auction, Auth::id())),
            __('auction.messages.participant_registered'),
            201
        );
    }

    public function acceptTerms(Request $request, Auction $auction, AcceptAuctionTermsAction $action): JsonResponse
    {
        $acceptance = $action->execute($auction, Auth::id(), $request->ip(), $request->userAgent());

        return $this->sendResponse(['id' => $acceptance->public_id], __('auction.messages.terms_accepted'), 201);
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
        Gate::authorize('confirmSellerHandover', $auction);

        return $this->auctionResponse(
            $action->execute($auction, Auth::id()),
            __('auction.messages.seller_handover_confirmed')
        );
    }

    public function confirmWinnerReceipt(Auction $auction, ConfirmAuctionReceiptByWinnerAction $action): JsonResponse
    {
        Gate::authorize('confirmWinnerReceipt', $auction);

        return $this->auctionResponse(
            $action->execute($auction, Auth::id()),
            __('auction.messages.winner_receipt_confirmed')
        );
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

    public function blockParticipant(
        Request $request,
        Auction $auction,
        AuctionParticipant $participant,
        BlockAuctionParticipantAction $action
    ): JsonResponse {
        Gate::authorize('blockParticipant', $auction);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->sendResponse(
            new AuctionParticipantResource($action->block($auction, $participant, Auth::id(), (string) $data['reason'])),
            __('auction.messages.participant_blocked')
        );
    }

    public function unblockParticipant(
        Request $request,
        Auction $auction,
        AuctionParticipant $participant,
        BlockAuctionParticipantAction $action
    ): JsonResponse {
        Gate::authorize('blockParticipant', $auction);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->sendResponse(
            new AuctionParticipantResource($action->unblock($auction, $participant, Auth::id(), (string) ($data['reason'] ?? ''))),
            __('auction.messages.participant_unblocked')
        );
    }

    public function mine(AuctionIndexRequest $request, ListSellerAuctionsAction $action): JsonResponse
    {
        $paginator = $action->execute(Auth::id(), $request->filters(), $request->perPage());
        $this->participation->forCollection($paginator->items(), $request->user());

        return $this->sendResponse(
            UserAuctionResource::collection($paginator),
            __('auction.messages.seller_auctions_fetched')
        );
    }

    private function submitPayment(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action,
        PaymentPurpose $purpose
    ): JsonResponse {
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
    }

    private function auctionResponse(Auction $auction, string $message, int $status = 200): JsonResponse
    {
        return $this->sendResponse(
            $this->userAuctionResource($auction, request()->user(), app(LoadAuctionDetailsAction::class)),
            $message,
            $status
        );
    }

    private function userAuctionResource(Auction $auction, ?object $user, LoadAuctionDetailsAction $action): UserAuctionResource
    {
        $loaded = $action->execute($auction, $user?->id);
        $loaded->load(['seller', 'disputes']);
        $this->participation->forCollection([$loaded], $user);

        return new UserAuctionResource($loaded);
    }

    private function loadAdminAuctionRelations(Auction $auction, object $user): void
    {
        $relations = [
            'seller',
            'winningBid',
            'settlement.winner',
            'configurationSnapshot',
            'disputes',
        ];

        if (Gate::forUser($user)->allows('viewAny', \App\Models\Auction\AuctionSellerPayout::class)) {
            $relations[] = 'settlement.sellerPayout';
        }

        if (Gate::forUser($user)->allows('viewAny', PaymentSubmission::class)) {
            $relations[] = 'deposits.user';
            $relations[] = 'deposits.paymentSubmissions.user';
            $relations[] = 'deposits.paymentSubmissions.paymentMethod';
            $relations[] = 'deposits.paymentSubmissions.transaction.refunds';
        }

        if (Gate::forUser($user)->allows('resolveDispute', $auction)) {
            $relations[] = 'disputes';
        }

        $relations[] = 'activeContentReview.decisions.decidedBy:id,name';

        $auction->load($relations);
    }
}

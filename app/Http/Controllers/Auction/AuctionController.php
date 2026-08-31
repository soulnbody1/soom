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
use App\Http\Requests\Auction\AcceptTermsActionRequest;
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
use App\Services\Auction\Actions\FinalizeAuctionAction;
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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[Group(name: 'المزادات', description: 'إنشاء المزادات وعرضها وإدارة دورة حياتها من طرف البائع والمزايد.', weight: 1)]
final class AuctionController extends Controller
{
    use ApiResponseTrait;

    private const ADMIN_GROUP = 'إدارة المزادات';

    private const ADMIN_GROUP_DESCRIPTION = 'عمليات المشرف على المزادات: المراجعة والاعتماد وحسم النزاعات وإدارة المشاركين.';

    private const ADMIN_GROUP_WEIGHT = 11;

    public function __construct(
        private readonly ParticipationStateResolver $participation,
    ) {}

    #[Endpoint(
        title: 'عرض قائمة المزادات',
        description: 'يعرض المزادات المتاحة للعامة مع دعم البحث والتصفية والترتيب والتقسيم إلى صفحات. عند إرسال رمز مصادقة صالح تُضاف حالة مشاركة المستخدم الحالي إلى كل مزاد في القائمة.'
    )]
    #[Response(200, description: 'قائمة المزادات مقسّمة إلى صفحات.')]
    public function index(AuctionIndexRequest $request, ListPublicAuctionsAction $action): JsonResponse
    {
        $paginator = $action->execute($request->filters(), $request->user()?->id, $request->perPage());
        $this->participation->forCollection($paginator->items(), $request->user());

        return $this->sendResponse(
            UserAuctionResource::collection($paginator),
            __('auction.messages.auctions_fetched')
        );
    }

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'عرض قائمة المزادات للمشرف',
        description: 'يعرض جميع المزادات في المنصة بغض النظر عن حالتها، مع تصفية إدارية موسّعة تشمل البائع والفترة الزمنية والتأخر في السداد أو التسليم ووجود نزاع أو انتظار تأمين البائع.'
    )]
    #[Response(200, description: 'قائمة المزادات بصيغتها الإدارية مقسّمة إلى صفحات.')]
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

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'عرض المشاركين في المزاد',
        description: 'يعرض المشاركين المسجّلين في المزاد وحالة كل مشارك، مع إمكانية التصفية بحالة المشاركة.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[QueryParameter('status', description: 'تصفية النتائج بحالة المشاركة في المزاد.')]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[Response(200, description: 'قائمة المشاركين مقسّمة إلى صفحات.')]
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

    #[Endpoint(
        title: 'عرض تفاصيل المزاد',
        description: 'يعرض التفاصيل الكاملة للمزاد كما تظهر للمستخدم ويسجّل مشاهدة له. يُرجع 404 إذا لم يكن المزاد متاحًا للعرض لصاحب الطلب.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'تفاصيل المزاد وحالة مشاركة صاحب الطلب فيه.')]
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

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'عرض تفاصيل المزاد للمشرف',
        description: 'يعرض التفاصيل الكاملة للمزاد مع بياناته الإدارية: التسوية والتأمينات وإثباتات الدفع والنزاعات ومراجعة المحتوى، بحسب صلاحيات المشرف الحالي.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'تفاصيل المزاد بصيغتها الإدارية.')]
    public function showForAdmin(Request $request, Auction $auction, LoadAuctionDetailsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $loaded = $action->execute($auction, null);
        $this->loadAdminAuctionRelations($loaded, $request->user());

        return $this->sendResponse(new AdminAuctionResource($loaded), __('auction.messages.auction_fetched'));
    }

    #[Endpoint(
        title: 'إنشاء مزاد جديد',
        description: 'ينشئ مزادًا جديدًا بحالة مسودة باسم البائع الحالي ويرفع صور المزاد إن وُجدت. لا يُنشر المزاد إلا بعد إرساله للمراجعة واعتماده. يُرسل الطلب بصيغة multipart/form-data.'
    )]
    #[Response(201, description: 'المزاد بعد إنشائه كمسودة.')]
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

    #[Endpoint(
        title: 'تعديل بيانات المزاد',
        description: 'يعدّل بيانات المزاد قبل نشره، وتُرسل الحقول المطلوب تعديلها فقط. تغيير العملة يستلزم إعادة إرسال المبالغ المرتبطة بها.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد التعديل.')]
    public function update(UpdateDraftAuctionRequest $request, Auction $auction, UpdateDraftAuctionAction $action): JsonResponse
    {
        Gate::authorize('update', $auction);

        $input = UpdateDraftAuctionInputDTO::fromValidated($request->validated());

        return $this->auctionResponse($action->execute($auction, $input, Auth::id()), __('auction.messages.auction_updated'));
    }

    #[Endpoint(
        title: 'إعادة فتح مزاد مرفوض',
        description: 'يعيد المزاد المرفوض إلى حالة المسودة ليتمكن البائع من تعديله وإرساله للمراجعة من جديد.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد إعادته إلى حالة المسودة.')]
    public function reopen(Auction $auction, ReopenRejectedAuctionAction $action): JsonResponse
    {
        Gate::authorize('reopen', $auction);

        return $this->auctionResponse($action->execute($auction, Auth::id()), __('auction.messages.auction_reopened'));
    }

    #[Endpoint(
        title: 'إرسال المزاد للمراجعة',
        description: 'ينقل المزاد من حالة المسودة إلى قائمة انتظار المراجعة وينشئ طلب مراجعة محتوى للمزاد.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد إرساله للمراجعة.')]
    public function submitForReview(
        AcceptTermsActionRequest $request,
        Auction $auction,
        SubmitAuctionForReviewAction $action
    ): JsonResponse {
        Gate::authorize('submitForReview', $auction);

        return $this->auctionResponse(
            $action->execute(
                $auction,
                Auth::id(),
                $request->termsVersionId(),
                $request->ip(),
                $request->userAgent()
            ),
            __('auction.messages.auction_submitted')
        );
    }

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'اعتماد المزاد أو رفضه',
        description: 'يسجّل قرار المشرف على مراجعة محتوى المزاد. الاعتماد ينقل المزاد إلى المرحلة التالية من دورة حياته، والرفض يعيده إلى البائع مرفقًا بسبب الرفض.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد تطبيق قرار المراجعة.')]
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

    #[Endpoint(
        title: 'إلغاء المزاد',
        description: 'يلغي المزاد ولا يحذفه، ويُلزم دائمًا بإرسال سبب الإلغاء. وعند تنفيذ العملية من مشرف يجب أيضًا تحديد تصنيف السبب والجهة المسؤولة لأنهما يحدّدان مصير التأمينات.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد إلغائه.')]
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

    #[Endpoint(
        title: 'إنهاء المزاد الآن',
        description: 'ينهي مزادًا مباشرًا قبل موعد انتهائه بقبول أعلى مزايدة حالية. يمر الإنهاء بمسار الإنهاء الطبيعي نفسه فيحدد الفائز وينشئ التسوية ويطبّق العربونات ويصدر الإشعارات. يُرفض الطلب إذا لم تكن هناك مزايدات أو كانت أعلى مزايدة أقل من السعر الاحتياطي، وإذا لم يكن المزاد مباشرًا. متاح لصاحب المزاد ولمن يملك صلاحية الإنهاء المبكر.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد إنهائه مع التسوية إن وُجدت.')]
    #[Response(409, description: 'المزاد ليس مباشرًا (early_end_not_available).')]
    #[Response(422, description: 'لا توجد مزايدات أو أعلى مزايدة أقل من السعر الاحتياطي.')]
    public function endEarly(Auction $auction, FinalizeAuctionAction $action): JsonResponse
    {
        Gate::authorize('endEarly', $auction);

        $ended = $action->executeEarly($auction, (int) Auth::id(), Auth::user()?->role === 'admin' ? 'admin' : 'user');

        return $this->auctionResponse($ended, __('auction.messages.auction_ended_early'));
    }

    #[Endpoint(
        title: 'التسجيل في المزاد',
        description: 'يسجّل المستخدم الحالي مشاركًا في المزاد تمهيدًا لقبول الشروط ودفع تأمين المزايد.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'بيانات مشاركة المستخدم في المزاد.')]
    #[Response(409, description: 'المستخدم مسجل بالفعل في هذا المزاد (already_registered).')]
    public function register(
        AcceptTermsActionRequest $request,
        Auction $auction,
        RegisterParticipantAction $action
    ): JsonResponse {
        Gate::authorize('register', $auction);

        return $this->sendResponse(
            new AuctionParticipantResource($action->execute(
                $auction,
                Auth::id(),
                $request->termsVersionId(),
                $request->ip(),
                $request->userAgent()
            )),
            __('auction.messages.participant_registered'),
            201
        );
    }

    #[Endpoint(
        title: 'قبول شروط المزاد',
        description: 'يوثّق قبول المستخدم لنسخة الشروط المرتبطة بالمزاد مع تسجيل عنوان الـIP ومعرّف المتصفح وقت القبول.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'المعرّف العام لسجل قبول الشروط.')]
    #[Response(409, description: 'سبق للمستخدم قبول نسخة الشروط المرتبطة بالمزاد (terms_already_accepted).')]
    public function acceptTerms(Request $request, Auction $auction, AcceptAuctionTermsAction $action): JsonResponse
    {
        $acceptance = $action->execute($auction, Auth::id(), $request->ip(), $request->userAgent());

        return $this->sendResponse(['id' => $acceptance->public_id], __('auction.messages.terms_accepted'), 201);
    }

    #[Endpoint(
        title: 'رفع إثبات دفع تأمين البائع',
        description: 'يرفع البائع إيصال تحويل تأمين البائع ليخضع لمراجعة المشرف. يُرسل الطلب بصيغة multipart/form-data، ويمنع مفتاح منع التكرار إنشاء إثبات مكرر عند إعادة الإرسال.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'إثبات الدفع بعد تسجيله بانتظار المراجعة.')]
    public function submitSellerDeposit(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action
    ): JsonResponse {
        return $this->submitPayment($request, $auction, $action, PaymentPurpose::SellerDeposit);
    }

    #[Endpoint(
        title: 'رفع إثبات دفع تأمين المزايد',
        description: 'يرفع المزايد إيصال تحويل تأمين المزايد ليخضع لمراجعة المشرف، وهو شرط للسماح له بتقديم المزايدات. يُرسل الطلب بصيغة multipart/form-data.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'إثبات الدفع بعد تسجيله بانتظار المراجعة.')]
    public function submitBidderDeposit(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action
    ): JsonResponse {
        return $this->submitPayment($request, $auction, $action, PaymentPurpose::BidderDeposit);
    }

    #[Endpoint(
        title: 'رفع إثبات سداد مستحقات الفائز',
        description: 'يرفع الفائز إيصال سداد قيمة المزاد المستحقة عليه ضمن مهلة السداد ليخضع لمراجعة المشرف. يُرسل الطلب بصيغة multipart/form-data.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'إثبات الدفع بعد تسجيله بانتظار المراجعة.')]
    public function submitWinnerPayment(
        PaymentSubmissionRequest $request,
        Auction $auction,
        SubmitPaymentSubmissionAction $action
    ): JsonResponse {
        return $this->submitPayment($request, $auction, $action, PaymentPurpose::WinnerSettlement);
    }

    #[Endpoint(
        title: 'تأكيد التسليم من البائع',
        description: 'يسجّل تأكيد البائع بأنه سلّم المنتج إلى الفائز، وهي خطوة لازمة لاستكمال تسوية المزاد.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد تسجيل تأكيد التسليم.')]
    #[Response(409, description: 'سبق تأكيد التسليم لهذا المزاد (handover_already_confirmed).')]
    public function confirmSellerHandover(Auction $auction, ConfirmAuctionHandoverBySellerAction $action): JsonResponse
    {
        Gate::authorize('confirmSellerHandover', $auction);

        return $this->auctionResponse(
            $action->execute($auction, Auth::id()),
            __('auction.messages.seller_handover_confirmed')
        );
    }

    #[Endpoint(
        title: 'تأكيد الاستلام من الفائز',
        description: 'يسجّل تأكيد الفائز باستلام المنتج، ما ينقل المزاد إلى مرحلة التسوية النهائية وصرف مستحقات البائع.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد تسجيل تأكيد الاستلام.')]
    public function confirmWinnerReceipt(Auction $auction, ConfirmAuctionReceiptByWinnerAction $action): JsonResponse
    {
        Gate::authorize('confirmWinnerReceipt', $auction);

        return $this->auctionResponse(
            $action->execute($auction, Auth::id()),
            __('auction.messages.winner_receipt_confirmed')
        );
    }

    #[Endpoint(
        title: 'فتح نزاع على المزاد',
        description: 'يفتح نزاعًا على مرحلة التسليم بين البائع والفائز، ما يوقف مسار الإنهاء التلقائي حتى يبتّ المشرف في النزاع.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'المعرّف العام للنزاع وحالته.')]
    #[Response(409, description: 'يوجد نزاع مفتوح بالفعل على هذا المزاد (dispute_already_open).')]
    public function openDispute(OpenAuctionDisputeRequest $request, Auction $auction, OpenAuctionDisputeAction $action): JsonResponse
    {
        Gate::authorize('openDispute', $auction);

        $dispute = $action->execute($auction, Auth::id(), (string) $request->validated('reason'));

        return $this->sendResponse([
            'id' => $dispute->public_id,
            'status' => $dispute->status,
        ], __('auction.messages.dispute_opened'), 201);
    }

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'حسم نزاع المزاد',
        description: 'يسجّل قرار المشرف في النزاع: إكمال التسوية أو استئناف التسليم أو إلغاء المزاد، مع تحديد مصير تأمين البائع والمبلغ المُصادَر منه عند المصادرة الجزئية.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[PathParameter('auctionDispute', description: 'المعرّف العام للنزاع (ULID).')]
    #[Response(200, description: 'المزاد بعد حسم النزاع.')]
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

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'تسجيل تخلّف الفائز',
        description: 'يسجّل تخلّف الفائز عن السداد ضمن المهلة المحددة، مع إمكانية ترحيل الفوز إلى المزايد التالي وتجاوز المهلة بمبرر مكتوب.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'المزاد بعد معالجة تخلّف الفائز.')]
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

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'حظر مشارك في المزاد',
        description: 'يمنع المشارك من متابعة المزايدة في هذا المزاد مع تسجيل سبب الحظر في سجل النشاط.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[PathParameter('participant', description: 'المعرّف العام لمشاركة المستخدم في المزاد (ULID).')]
    #[BodyParameter('reason', description: 'سبب حظر المشارك.')]
    #[Response(200, description: 'بيانات المشاركة بعد الحظر.')]
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

    #[Group(self::ADMIN_GROUP, self::ADMIN_GROUP_DESCRIPTION, self::ADMIN_GROUP_WEIGHT)]
    #[Endpoint(
        title: 'رفع الحظر عن مشارك',
        description: 'يعيد إلى المشارك المحظور القدرة على المزايدة في هذا المزاد.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[PathParameter('participant', description: 'المعرّف العام لمشاركة المستخدم في المزاد (ULID).')]
    #[BodyParameter('reason', description: 'سبب رفع الحظر عن المشارك.')]
    #[Response(200, description: 'بيانات المشاركة بعد رفع الحظر.')]
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

    #[Endpoint(
        title: 'عرض مزاداتي كبائع',
        description: 'يعرض المزادات التي أنشأها المستخدم الحالي بجميع حالاتها، بما فيها المسودات والمزادات المرفوضة.'
    )]
    #[Response(200, description: 'قائمة مزادات البائع مقسّمة إلى صفحات.')]
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
            $relations[] = 'deposits.paymentSubmissions.transaction';
            $relations[] = 'deposits.paymentTransaction.paymentMethod';
            $relations[] = 'deposits.paymentTransaction.submission.paymentMethod';
            $relations[] = 'deposits.refunds';
            $relations[] = 'refunds';
        }

        $relations[] = 'activeContentReview.decisions.decidedBy:id,name';

        $auction->load($relations);
    }
}

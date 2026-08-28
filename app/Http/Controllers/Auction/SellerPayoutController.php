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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

#[Group(name: 'مستحقات البائع', description: 'مستحقات البائعين بعد اكتمال التسوية ومسار صرفها ومتابعتها.', weight: 7)]
final class SellerPayoutController extends Controller
{
    use ApiResponseTrait;

    private const ADMIN_RELATIONS = ['auction:id,public_id,title', 'seller:id,name,phone', 'settlement', 'processor:id,name', 'payer:id,name'];

    #[Endpoint(
        title: 'عرض مستحقات البائعين',
        description: 'يعرض مستحقات جميع البائعين مع بيانات المزاد والبائع والمبلغ وحالة الصرف، مع إمكانية التصفية بالحالة أو بالمزاد أو بالبائع أو بفترة زمنية.'
    )]
    #[Response(200, description: 'قائمة المستحقات مقسّمة إلى صفحات.')]
    public function index(AdminSellerPayoutIndexRequest $request, AuctionSellerPayoutRepository $payouts): JsonResponse
    {
        Gate::authorize('viewAny', AuctionSellerPayout::class);

        $paginator = $payouts
            ->paginateForAdmin($request->filters(), $request->perPage())
            ->through(fn (AuctionSellerPayout $payout) => new SellerPayoutResource($payout));

        return $this->sendResponse($paginator, __('auction.messages.payouts_fetched'));
    }

    #[Endpoint(
        title: 'عرض ملخّص المستحقات',
        description: 'يعرض عدد المستحقات وإجمالي مبالغها مجمّعةً حسب حالة الصرف.'
    )]
    #[Response(200, description: 'ملخّص المستحقات مصنّفًا بحسب الحالة.')]
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

    #[Endpoint(
        title: 'عرض تفاصيل مستحق البائع',
        description: 'يعرض تفاصيل المستحق مع بيانات المزاد والبائع والتسوية ومن نفّذ الصرف، إضافةً إلى حالة استرداد تأمين البائع المرتبط به.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'تفاصيل المستحق وحالة استرداد تأمين البائع.')]
    public function show(AuctionSellerPayout $sellerPayout, AuctionSellerPayoutRepository $payouts): JsonResponse
    {
        Gate::authorize('view', $sellerPayout);

        $sellerPayout->load(self::ADMIN_RELATIONS);
        $payload = (new SellerPayoutResource($sellerPayout))->resolve();
        $payload['seller_deposit_refund'] = $this->sellerDepositRefund($sellerPayout, $payouts);

        return $this->sendResponse($payload, __('auction.messages.payout_fetched'));
    }

    #[Endpoint(
        title: 'بدء معالجة الصرف',
        description: 'ينقل المستحق إلى حالة قيد المعالجة ويحجزه باسم المشرف الحالي تمهيدًا لتنفيذ التحويل.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'المستحق بعد بدء معالجته.')]
    public function startProcessing(Request $request, AuctionSellerPayout $sellerPayout, StartSellerPayoutProcessingAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        return $this->transitionResponse(
            fn () => $action->execute($sellerPayout, $request->user()),
            __('auction.messages.payout_processing_started')
        );
    }

    #[Endpoint(
        title: 'تسجيل صرف المستحق',
        description: 'يسجّل تنفيذ تحويل المستحق إلى البائع مع رفع إثبات التحويل، ويسمح بتجاوز وجهة التحويل المحفوظة ببيانات مستفيد بديلة. يُرسل الطلب بصيغة multipart/form-data.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'المستحق بعد تسجيله مصروفًا.')]
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

    #[Endpoint(
        title: 'تسجيل فشل الصرف',
        description: 'يسجّل تعذّر تنفيذ التحويل، إما بوضع المستحق في حالة الفشل أو بإحالته إلى المراجعة اليدوية، مع توثيق السبب.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'المستحق بعد تسجيل الفشل أو الإحالة.')]
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

    #[Endpoint(
        title: 'تعليق صرف المستحق',
        description: 'يوقف صرف المستحق مؤقتًا ويمنع معالجته حتى رفع التعليق، مع توثيق السبب.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[BodyParameter('reason', description: 'سبب تعليق صرف المستحق.')]
    #[Response(200, description: 'المستحق بعد تعليقه.')]
    public function hold(Request $request, AuctionSellerPayout $sellerPayout, HoldSellerPayoutAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->transitionResponse(
            fn () => $action->hold($sellerPayout, $request->user(), (string) $data['reason']),
            __('auction.messages.payout_held')
        );
    }

    #[Endpoint(
        title: 'رفع تعليق المستحق',
        description: 'يرفع التعليق عن المستحق ويعيده إلى مسار الصرف المعتاد.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'المستحق بعد رفع التعليق عنه.')]
    public function release(Request $request, AuctionSellerPayout $sellerPayout, HoldSellerPayoutAction $action): JsonResponse
    {
        Gate::authorize('process', $sellerPayout);

        return $this->transitionResponse(
            fn () => $action->release($sellerPayout, $request->user()),
            __('auction.messages.payout_released')
        );
    }

    #[Endpoint(
        title: 'إنشاء رابط مؤقت لإثبات التحويل',
        description: 'ينشئ رابطًا مؤقتًا لتحميل ملف إثبات التحويل. الإثبات محفوظ في مخزن خاص (S3/Spaces) لا يمكن الوصول إليه مباشرة، فيُصدر النظام رابط pre-signed URL صالحًا لعشر دقائق فقط ويُرجع معه تاريخ انتهاء صلاحيته في expires_at. تُنفَّذ هذه العملية عبر مسارين لهما السلوك نفسه ويختلفان في الجمهور: مسار البائع GET /api/soom/my/payouts/{sellerPayout}/proof-url، ومسار الإدارة GET /api/admin/auctions/payouts/{sellerPayout}/proof-url. يُرجع 404 إذا لم يكن للمستحق إثبات مرفوع أو تعذّر إنشاء الرابط.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'الرابط المؤقت وتاريخ انتهاء صلاحيته.')]
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

    #[Endpoint(
        title: 'عرض مستحقاتي كبائع',
        description: 'يعرض مستحقات المستخدم الحالي بصيغة مختصرة تناسب البائع، مع إمكانية التصفية بحالة الصرف.'
    )]
    #[QueryParameter('status', description: 'تصفية المستحقات بحالة الصرف.')]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[Response(200, description: 'قائمة مستحقات البائع مقسّمة إلى صفحات.')]
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

    #[Endpoint(
        title: 'عرض تفاصيل مستحقي كبائع',
        description: 'يعرض تفاصيل مستحق واحد يخص المستخدم الحالي. يُرجع 404 إذا كان المستحق يخص بائعًا آخر.'
    )]
    #[PathParameter('sellerPayout', description: 'المعرّف العام لمستحق البائع (ULID).')]
    #[Response(200, description: 'تفاصيل المستحق بصيغته المخصصة للبائع.')]
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\PaymentMethodResource;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;
use App\Services\Auction\Actions\CreatePaymentMethodAction;
use App\Services\Auction\Actions\ListPaymentMethodsAction;
use App\Services\Auction\Actions\UpdatePaymentMethodAction;
use App\Services\Auction\Support\PaymentMethodDisclosureRule;
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
use Illuminate\Validation\Rule;

#[Group(name: 'طرق الدفع', description: 'طرق الدفع المعتمدة في المنصة وبيانات التحويل الخاصة بها.', weight: 5)]
final class PaymentMethodController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض طرق الدفع',
        description: 'يعرض طرق الدفع المفعّلة دون بيانات التحويل الحساسة، وهو متاح دون مصادقة.'
    )]
    #[Response(200, description: 'قائمة طرق الدفع المفعّلة.')]
    public function index(ListPaymentMethodsAction $action): JsonResponse
    {
        return $this->sendResponse(
            PaymentMethodResource::collection($action->execute()),
            __('auction.messages.payment_methods_fetched')
        );
    }

    #[Endpoint(
        title: 'عرض طرق الدفع الخاصة بمزاد',
        description: 'يعرض طرق الدفع مع بيانات التحويل الكاملة لغرض دفعة محدد. لا تُكشف هذه البيانات إلا لمن يحق له تنفيذ الدفعة في هذا المزاد وفي المرحلة المناسبة من دورة حياته.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[QueryParameter('purpose', description: 'غرض الدفعة المطلوب: bidder_deposit لتأمين المزايد، أو seller_deposit لتأمين البائع، أو winner_payment لسداد مستحقات الفائز.')]
    #[Response(200, description: 'قائمة طرق الدفع مع بيانات التحويل.')]
    public function forAuction(
        Request $request,
        Auction $auction,
        ListPaymentMethodsAction $action,
        PaymentMethodDisclosureRule $rule
    ): JsonResponse {
        if (! Gate::allows('view', $auction)) {
            return $this->sendError(__('auction.errors.auction_not_found'), 404, 'auction_not_found');
        }

        $data = $request->validate([
            'purpose' => ['required', Rule::in(['bidder_deposit', 'seller_deposit', 'winner_payment'])],
        ]);

        $purpose = match ($data['purpose']) {
            'bidder_deposit' => PaymentPurpose::BidderDeposit,
            'seller_deposit' => PaymentPurpose::SellerDeposit,
            default => PaymentPurpose::WinnerSettlement,
        };

        $rule->assertCanSee($auction, $request->user(), $purpose);

        return $this->sendResponse(
            PaymentMethodResource::detailedCollection($action->execute()),
            __('auction.messages.payment_methods_fetched')
        );
    }

    #[Endpoint(
        title: 'إضافة طريقة دفع',
        description: 'ينشئ طريقة دفع جديدة ببيانات التحويل الخاصة بها. رمز الطريقة فريد ولا يمكن تكراره.'
    )]
    #[BodyParameter('name', description: 'الاسم الظاهر لطريقة الدفع.')]
    #[BodyParameter('code', description: 'الرمز الفريد لطريقة الدفع، ولا يمكن تعديله بعد الإنشاء.')]
    #[BodyParameter('recipient_name', description: 'اسم المستفيد الذي يحوّل إليه المستخدم.')]
    #[BodyParameter('identifier_type', description: 'نوع معرّف التحويل، مثل رقم الحساب أو الآيبان أو رقم المحفظة.')]
    #[BodyParameter('identifier_value', description: 'قيمة معرّف التحويل المطابقة للنوع المختار.')]
    #[BodyParameter('instructions', description: 'تعليمات التحويل التي تظهر للمستخدم.')]
    #[BodyParameter('requires_manual_review', description: 'إلزام مراجعة المشرف يدويًا لكل إثبات دفع يُرسل عبر هذه الطريقة.')]
    #[BodyParameter('is_active', description: 'إتاحة طريقة الدفع للاستخدام.')]
    #[Response(201, description: 'طريقة الدفع بعد إنشائها مع بيانات التحويل.')]
    public function store(Request $request, CreatePaymentMethodAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:80', 'unique:payment_methods,code'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'identifier_type' => ['nullable', 'string', Rule::in(PaymentMethod::IDENTIFIER_TYPES), 'required_with:identifier_value'],
            'identifier_value' => ['nullable', 'string', 'max:190', 'required_with:identifier_type'],
            'instructions' => ['nullable', 'string'],
            'requires_manual_review' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->sendResponse(
            (new PaymentMethodResource($action->execute($data)))->withTransferDetails(),
            __('auction.messages.payment_method_created'),
            201
        );
    }

    #[Endpoint(
        title: 'عرض تفاصيل طريقة دفع',
        description: 'يعرض بيانات طريقة دفع واحدة دون بيانات التحويل الحساسة.'
    )]
    #[PathParameter('paymentMethod', description: 'المعرّف العام لطريقة الدفع (ULID).')]
    #[Response(200, description: 'بيانات طريقة الدفع.')]
    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return $this->sendResponse(new PaymentMethodResource($paymentMethod), __('auction.messages.payment_method_fetched'));
    }

    #[Endpoint(
        title: 'تعديل طريقة دفع',
        description: 'يعدّل بيانات طريقة دفع قائمة. تُرسل الحقول المطلوب تعديلها فقط، ولا يمكن تغيير رمز الطريقة.'
    )]
    #[PathParameter('paymentMethod', description: 'المعرّف العام لطريقة الدفع (ULID).')]
    #[BodyParameter('name', description: 'الاسم الظاهر لطريقة الدفع.')]
    #[BodyParameter('recipient_name', description: 'اسم المستفيد الذي يحوّل إليه المستخدم.')]
    #[BodyParameter('identifier_type', description: 'نوع معرّف التحويل، مثل رقم الحساب أو الآيبان أو رقم المحفظة.')]
    #[BodyParameter('identifier_value', description: 'قيمة معرّف التحويل المطابقة للنوع المختار.')]
    #[BodyParameter('instructions', description: 'تعليمات التحويل التي تظهر للمستخدم.')]
    #[BodyParameter('requires_manual_review', description: 'إلزام مراجعة المشرف يدويًا لكل إثبات دفع يُرسل عبر هذه الطريقة.')]
    #[BodyParameter('is_active', description: 'إتاحة طريقة الدفع للاستخدام.')]
    #[Response(200, description: 'طريقة الدفع بعد التعديل مع بيانات التحويل.')]
    public function update(Request $request, PaymentMethod $paymentMethod, UpdatePaymentMethodAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'identifier_type' => ['nullable', 'string', Rule::in(PaymentMethod::IDENTIFIER_TYPES), 'required_with:identifier_value'],
            'identifier_value' => ['nullable', 'string', 'max:190', 'required_with:identifier_type'],
            'instructions' => ['nullable', 'string'],
            'requires_manual_review' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->sendResponse(
            (new PaymentMethodResource($action->execute($paymentMethod, $data)))->withTransferDetails(),
            __('auction.messages.payment_method_updated')
        );
    }
}

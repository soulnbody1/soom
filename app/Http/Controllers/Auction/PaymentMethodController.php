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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PaymentMethodController extends Controller
{
    use ApiResponseTrait;

    public function index(ListPaymentMethodsAction $action): JsonResponse
    {
        return $this->sendResponse(
            PaymentMethodResource::collection($action->execute()),
            __('auction.messages.payment_methods_fetched')
        );
    }

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

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return $this->sendResponse(new PaymentMethodResource($paymentMethod), __('auction.messages.payment_method_fetched'));
    }

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

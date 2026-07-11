<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\PaymentMethodResource;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;
use App\Services\Auction\Actions\CreatePaymentMethodAction;
use App\Services\Auction\Actions\ListPaymentMethodsAction;
use App\Services\Auction\Actions\UpdatePaymentMethodAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

    public function store(Request $request, CreatePaymentMethodAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:80', 'unique:payment_methods,code'],
            'instructions' => ['nullable', 'string'],
            'requires_manual_review' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->sendResponse(
            new PaymentMethodResource($action->execute($data)),
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
            'instructions' => ['nullable', 'string'],
            'requires_manual_review' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->sendResponse(new PaymentMethodResource($action->execute($paymentMethod, $data)), __('auction.messages.payment_method_updated'));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\PaymentMethodResource;
use App\Models\Auction\PaymentMethod;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentMethodController extends Controller
{
    use ApiResponseTrait;

    public function index(): JsonResponse
    {
        return $this->sendResponse(
            PaymentMethodResource::collection(PaymentMethod::where('is_active', true)->orderBy('name')->get()),
            'Payment methods fetched.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return $this->sendError('Forbidden.', 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:80', 'unique:payment_methods,code'],
            'instructions' => ['nullable', 'string'],
            'requires_manual_review' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->sendResponse(
            new PaymentMethodResource(PaymentMethod::create($data)),
            'Payment method created.',
            201
        );
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return $this->sendResponse(new PaymentMethodResource($paymentMethod), 'Payment method fetched.');
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return $this->sendError('Forbidden.', 403);
        }

        $paymentMethod->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'instructions' => ['nullable', 'string'],
            'requires_manual_review' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return $this->sendResponse(new PaymentMethodResource($paymentMethod->refresh()), 'Payment method updated.');
    }
}

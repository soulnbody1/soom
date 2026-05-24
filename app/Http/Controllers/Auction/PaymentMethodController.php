<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Traits\ApiResponseTrait;

class PaymentMethodController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {
        $methods = PaymentMethod::orderBy('id')->get();
        return PaymentMethodResource::collection($methods);
    }

    public function store(PaymentMethodRequest $request)
    {
        $data = $request->validated();

        // لو أول طريقة دفع نخليها default
        if (PaymentMethod::count() === 0) {
            $data['is_default'] = true;
        }

        // لو user اختار default، نشيل الـ default عن الباقي
        if (!empty($data['is_default'])) {
            PaymentMethod::where('is_default', true)->update(['is_default' => false]);
        }

        $method = PaymentMethod::create($data);
        return new PaymentMethodResource($method);
    }

    public function show($id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return $this->sendError('طريقة الدفع غير موجودة.', 404);
        }
        return new PaymentMethodResource($method);
    }

    public function update(PaymentMethodRequest $request, $id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return $this->sendError('طريقة الدفع غير موجودة.', 404);
        }

        $data = $request->validated();

        // لو user اختار default، نشيل الـ default عن الباقي
        if (!empty($data['is_default'])) {
            PaymentMethod::where('is_default', true)->where('id', '!=', $id)->update(['is_default' => false]);
        }

        $method->update($data);
        return new PaymentMethodResource($method->fresh());
    }

    public function destroy($id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return $this->sendError('طريقة الدفع غير موجودة.', 404);
        }
        $method->delete();
        return $this->sendResponse([], 'تم حذف طريقة الدفع بنجاح.');
    }
}
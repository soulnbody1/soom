<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentSlipRequest;
use App\Http\Resources\PaymentSlipResource;
use App\Models\PaymentSlip;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Storage;

class PaymentSlipController extends Controller
{
    use ApiResponseTrait;

    public function store(PaymentSlipRequest $request)
    {
        $data = $request->validated();

        // تحويل نوع الدفع لمودل فعلي
        $payableType = $data['payable_type'] === 'auction'
            ? Auction::class
            : AuctionBid::class;

        // رفع الصورة
        $imagePath = $request->file('image')->store('payment_slips', 'spaces');

        $slip = PaymentSlip::create([
            'image_path' => $imagePath,
            'payment_method_id' => $data['payment_method_id'],
            'amount' => $data['amount'],
            'payable_id' => $data['payable_id'],
            'payable_type' => $payableType,
        ]);

        return $this->sendResponse(
            new PaymentSlipResource($slip->load('paymentMethod')),
            'تم رفع إيصال الدفع بنجاح.',
            201
        );
    }

    public function index()
    {
        $slips = PaymentSlip::with('paymentMethod')->latest()->get();
        return PaymentSlipResource::collection($slips);
    }

    public function show($id)
    {
        $slip = PaymentSlip::with('paymentMethod')->find($id);
        if (!$slip) {
            return $this->sendError('إيصال الدفع غير موجود.', 404);
        }
        return new PaymentSlipResource($slip);
    }

    public function destroy($id)
    {
        $slip = PaymentSlip::find($id);
        if (!$slip) {
            return $this->sendError('إيصال الدفع غير موجود.', 404);
        }

        // حذف الصورة من التخزين
        Storage::disk('spaces')->delete($slip->image_path);
        $slip->delete();

        return $this->sendResponse([], 'تم حذف إيصال الدفع بنجاح.');
    }

    /**
     * جلب إيصالات مزاد معين
     */
    public function byAuction($auctionId)
    {
        $slips = PaymentSlip::with('paymentMethod')
            ->where('payable_type', Auction::class)
            ->where('payable_id', $auctionId)
            ->latest()
            ->get();

        return PaymentSlipResource::collection($slips);
    }

    /**
     * جلب إيصالات مزايدة معينة
     */
    public function byBid($bidId)
    {
        $slips = PaymentSlip::with('paymentMethod')
            ->where('payable_type', AuctionBid::class)
            ->where('payable_id', $bidId)
            ->latest()
            ->get();

        return PaymentSlipResource::collection($slips);
    }
}
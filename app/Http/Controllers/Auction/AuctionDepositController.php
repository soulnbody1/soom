<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuctionDepositResource;
use App\Models\AuctionDeposit;
use App\Models\Auction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuctionDepositController extends Controller
{
    use ApiResponseTrait;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * قائمة التأمينات (للأدمن فقط)
     */
    public function index(Request $request)
    {
        if (Auth::user()?->role !== 'admin') {
            return $this->sendError('غير مصرح لك بتنفيذ هذا الإجراء.', 403);
        }

        $query = AuctionDeposit::with(['user', 'verifiedBy']);

        // فلترة حسب النوع
        if ($request->type === 'advertiser') {
            $query->advertiserDeposits();
        } elseif ($request->type === 'bidder') {
            $query->bidderDeposits();
        }

        // فلترة حسب الحالة
        if ($request->status) {
            $query->where('deposit_status', $request->status);
        }

        // فلترة حسب التحقق
        if ($request->verified === 'yes') {
            $query->whereNotNull('verified_at');
        } elseif ($request->verified === 'no') {
            $query->whereNull('verified_at');
        }

        $perPage = $request->get('per_page', 20);
        $deposits = $query->latest()->paginate($perPage);

        return $this->sendResponse(
            AuctionDepositResource::collection($deposits),
            'تم جلب التأمينات بنجاح.'
        );
    }

    /**
     * عرض تفاصيل تأمين معين (للأدمن فقط)
     */
    public function show($id)
    {
        if (Auth::user()?->role !== 'admin') {
            return $this->sendError('غير مصرح لك بتنفيذ هذا الإجراء.', 403);
        }

        $deposit = AuctionDeposit::with(['user', 'verifiedBy', 'depositable'])->find($id);
        if (!$deposit) {
            return $this->sendError('التأمين غير موجود.', 404);
        }

        return $this->sendResponse(
            new AuctionDepositResource($deposit),
            'تفاصيل التأمين.'
        );
    }

    /**
     * تأكيد أو رفض التأمين (للأدمن فقط)
     * Body: {"action": "verified"} أو {"action": "rejected"}
     */
    public function process(Request $request, $id)
    {
        if (Auth::user()?->role !== 'admin') {
            return $this->sendError('غير مصرح لك بتنفيذ هذا الإجراء.', 403);
        }

        $request->validate([
            'action' => 'required|in:verified,rejected',
        ]);

        $deposit = AuctionDeposit::find($id);
        if (!$deposit) {
            return $this->sendError('التأمين غير موجود.', 404);
        }

        if ($deposit->verified_at) {
            return $this->sendError('تمت معالجة هذا التأمين مسبقاً.', 400);
        }

        if ($request->action === 'verified') {
            // تأكيد الدفع
            $deposit->update([
                'paid' => true,
                'paid_at' => $deposit->paid_at ?? now(),
                'verified_at' => now(),
                'verified_by' => Auth::id(),
            ]);

            // لو هو تأمين معلن - تفعيل المزاد
            if ($deposit->isAdvertiserDeposit()) {
                $auction = $deposit->depositable;
                if ($auction && $auction instanceof Auction) {
                    $auction->update([
                        'status' => 'active',
                        'starts_at' => $auction->starts_at ?? now(),
                    ]);
                }
            }

            $message = 'تم تأكيد دفع التأمين بنجاح.';
        } else {
            // رفض الدفع
            $deposit->update([
                'deposit_status' => 'forfeited',
                'processed_at' => now(),
            ]);

            $message = 'تم رفض التأمين.';
        }

        return $this->sendResponse(
            new AuctionDepositResource($deposit->fresh(['user', 'verifiedBy'])),
            $message
        );
    }

    /**
     * التأمينات الخاصة بالمستخدم الحالي
     */
    public function myDeposits()
    {
        $userId = Auth::id();

        $deposits = AuctionDeposit::with(['depositable'])
            ->forUser($userId)
            ->latest()
            ->get();

        return $this->sendResponse(
            AuctionDepositResource::collection($deposits),
            'تم جلب تأميناتك بنجاح.'
        );
    }

    /**
     * تأمينات مزاد معين (للأدمن فقط)
     */
    public function byAuction($auctionId)
    {
        if (Auth::user()?->role !== 'admin') {
            return $this->sendError('غير مصرح لك بتنفيذ هذا الإجراء.', 403);
        }

        $deposits = AuctionDeposit::with(['user'])
            ->forAuction($auctionId)
            ->latest()
            ->get();

        return $this->sendResponse(
            AuctionDepositResource::collection($deposits),
            'تم جلب تأمينات المزاد بنجاح.'
        );
    }
}
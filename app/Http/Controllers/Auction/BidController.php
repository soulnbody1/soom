<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceBidRequest;
use App\Http\Requests\PayAuctionDepositRequest;
use App\Http\Resources\AuctionBidResource;
use App\Http\Resources\AuctionResource;
use App\Services\AuctionBidService;
use App\Services\AuctionDepositService;
use App\Repositories\AuctionRepository;
use App\Repositories\AuctionBidRepository;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BidController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected AuctionBidService $bidService,
        protected AuctionDepositService $depositService,
        protected AuctionRepository $auctionRepo,
        protected AuctionBidRepository $bidRepo
    ) {}

    /**
     * الحصول على معلومات التأمين المطلوب
     */
    public function getDepositInfo(int $auctionId)
    {
        $auction = $this->auctionRepo->findById($auctionId);

        if (!$auction) {
            return $this->sendError('المزاد غير موجود.', 404);
        }

        if (!$auction->isActive()) {
            return $this->sendError('المزاد غير متاح للمزايدة.', 400);
        }

        $userId = Auth::id();
        $isPaid = $this->bidService->isDepositPaid($auction, $userId);

        return $this->sendResponse([
            'deposit_amount' => $auction->getDepositAmount(),
            'deposit_type' => $auction->deposit_type,
            'is_paid' => $isPaid,
            'currency' => 'د.أ',
        ], 'معلومات التأمين.');
    }

    /**
     * دفع تأمين المزايد
     */
    public function payBidDeposit(int $auctionId, PayAuctionDepositRequest $request)
    {
        try {
            $auction = $this->auctionRepo->findById($auctionId);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            if (!$auction->isActive()) {
                return $this->sendError('المزاد غير متاح للمزايدة.', 400);
            }

            // التحقق من عدم كون المستخدم صاحب المزاد
            if ($auction->user_id === Auth::id()) {
                return $this->sendError('لا يمكنك المزايدة على مزادك.', 403);
            }

            $data = $request->validated();
            $userId = Auth::id();

            $transactionId = $this->depositService->payBidderDeposit(
                $auction,
                $userId,
                $data['payment_method'],
                ['token' => $data['payment_token']]
            );

            return $this->sendResponse(
                [
                    'transaction_id' => $transactionId,
                    'deposit_paid' => true,
                ],
                'تم دفع التأمين بنجاح. يمكنك الآن المزايدة.'
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * إضافة مزايدة
     */
    public function store(int $auctionId, PlaceBidRequest $request)
    {
        try {
            $auction = $this->auctionRepo->findById($auctionId);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            if (!$auction->isActive()) {
                return $this->sendError('المزاد غير متاح للمزايدة.', 400);
            }

            $data = $request->validated();
            $userId = Auth::id();

            $bid = $this->bidService->placeBid(
                $auction,
                $userId,
                (float) $data['amount'],
                $data['deposit_transaction_id'],
                $data['terms_accepted'] ?? false
            );

            return $this->sendResponse(
                new AuctionBidResource($bid->load(['user', 'auction'])),
                'تمت المزايدة بنجاح! أنت الآن أعلى مزايد.',
                201,
                [
                    'is_highest' => true,
                    'new_current_bid' => $bid->amount,
                ]
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * رفع المزايدة (زيادة مبلغ مزايدة موجودة)
     */
    public function increaseBid(int $auctionId, Request $request)
    {
        try {
            $auction = $this->auctionRepo->findById($auctionId);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            if (!$auction->isActive()) {
                return $this->sendError('المزاد غير متاح للمزايدة.', 400);
            }

            $data = $request->validate([
                'amount' => 'required|numeric|min:1|max:999999999.99',
                'terms_accepted' => 'required|accepted',
            ]);

            $userId = Auth::id();

            $bid = $this->bidService->increaseBid(
                $auction,
                $userId,
                (float) $data['amount'],
                $data['terms_accepted'] ?? false
            );

            return $this->sendResponse(
                [
                    'highest_bid_amount' => (float) $auction->current_bid,
                    'my_bid_amount' => (float) $bid->amount,
                    'bid_status' => $bid->amount >= $auction->current_bid ? 'أعلى مزايد' : 'تم تجاوزك',
                ],
                'تم رفع المزايدة بنجاح! أنت الآن أعلى مزايد.',
                200
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * قائمة المزايدات على مزاد
     */
    public function index(int $auctionId, Request $request)
    {
        $auction = $this->auctionRepo->findById($auctionId);

        if (!$auction) {
            return $this->sendError('المزاد غير موجود.', 404);
        }

        $perPage = $request->get('per_page', 20);
        $bids = $this->bidService->getBidsForAuction($auctionId, $perPage);

        return $this->sendResponse(
            AuctionBidResource::collection($bids),
            'تم جلب المزايدات بنجاح.'
        );
    }

    /**
     * مزايداتي (المستخدم الحالي)
     */
    public function myBids(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $userId = Auth::id();

        $bids = $this->bidService->getMyBids($userId, $perPage);

        return $this->sendResponse(
            AuctionBidResource::collection($bids),
            'تم جلب مزايداتك بنجاح.'
        );
    }

    /**
     * أعلى مزايدة على مزاد
     */
    public function highestBid(int $auctionId)
    {
        $auction = $this->auctionRepo->findById($auctionId);

        if (!$auction) {
            return $this->sendError('المزاد غير موجود.', 404);
        }

        $highestBid = $this->bidService->getHighestBid($auctionId);

        if (!$highestBid) {
            return $this->sendEmptyResponse('لا توجد مزايدات على هذا المزاد بعد.');
        }

        return $this->sendResponse(
            new AuctionBidResource($highestBid->load(['user'])),
            'أعلى مزايدة.'
        );
    }
}

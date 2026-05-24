<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuctionRequest;
use App\Http\Requests\UpdateAuctionRequest;
use App\Http\Requests\PayAuctionDepositRequest;
use App\Http\Resources\AuctionResource;
use App\Http\Resources\MyAuctionResource;
use App\Services\AuctionService;
use App\Services\AuctionDepositService;
use App\Repositories\AuctionRepository;
use App\Models\PaymentSlip;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AuctionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected AuctionService $auctionService,
        protected AuctionDepositService $depositService,
        protected AuctionRepository $auctionRepo
    ) {}

    /**
     * إنشاء مزاد جديد
     */
    public function store(StoreAuctionRequest $request)
    {
        try {
            $data = $request->validated();
            $userId = Auth::id();

            $auction = $this->auctionService->create($data, $userId);

            return $this->sendResponse(
                new AuctionResource($auction),
                'تم إنشاء المزاد بنجاح. يرجى دفع التأمين لتفعيله.',
                201,
                [
                    'deposit_amount' => $auction->getDepositAmount(),
                    'payment_required' => true,
                ]
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * دفع تأمين المعلن
     */
    public function payDeposit(int $id, PayAuctionDepositRequest $request)
    {
        try {
            $auction = $this->auctionRepo->findById($id);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            if ($auction->user_id !== Auth::id()) {
                return $this->sendError('لا يمكنك دفع التأمين لهذا المزاد.', 403);
            }

            if ($auction->status !== 'pending_payment') {
                return $this->sendError('المزاد ليس في حالة انتظار الدفع.', 400);
            }

            $data = $request->validated();

            // رفع صورة إيصال الدفع
            $imagePath = $request->file('image')->store('payment_slips', 'spaces');

            // إنشاء سجل إيصال الدفع مرتبط بالمزاد
            $paymentSlip = PaymentSlip::create([
                'image_path' => $imagePath,
                'payment_method_id' => $data['payment_method_id'],
                'amount' => $data['amount'] ?? $auction->getDepositAmount(),
                'payable_id' => $auction->id,
                'payable_type' => \App\Models\Auction::class,
            ]);

            // تحديث حالة المزاد كمدفوع
            $this->auctionService->payAdvertiserDeposit($auction, $paymentSlip->id);

            return $this->sendResponse(
                [
                    'auction' => new AuctionResource($auction->fresh()),
                    'payment_slip' => new \App\Http\Resources\PaymentSlipResource($paymentSlip->load('paymentMethod')),
                ],
                'تم دفع التأمين بنجاح. المزاد الآن نشط.',
                200
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * عرض قائمة المزادات النشطة
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $auctions = $this->auctionService->getActiveAuctions([], $perPage);

        return $this->sendResponse(
            AuctionResource::collection($auctions),
            'تم جلب المزادات بنجاح.'
        );
    }

    /**
     * جلب كل المزادات بكل حالاتها (للأدمن فقط)
     */
    public function all(Request $request)
    {
        if (Auth::user()?->role !== 'admin') {
            return $this->sendError('غير مصرح لك بتنفيذ هذا الإجراء.', 403);
        }

        $perPage = $request->get('per_page', 20);
        $auctions = \App\Models\Auction::with(['images', 'category', 'user', 'winningBid.user'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->sendResponse(
            AuctionResource::collection($auctions),
            'تم جلب كل المزادات بنجاح.'
        );
    }

    /**
     * عرض تفاصيل مزاد مع المزايدات
     */
    public function show(int $id)
    {
        $relations = [
            'images',
            'category',
            'user',
            'country',
            'state',
            'city',
            'winningBid.user',
            'bids' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(10);
            },
            'bids.user:id,name,phone',
        ];

        $auction = $this->auctionService->getAuction($id, $relations);

        if (!$auction) {
            return $this->sendError('المزاد غير موجود.', 404);
        }

        // تسجيل المشاهدة (View)
        $this->recordView($auction);

        $userId = Auth::id();
        
        // جلب مزايدة المستخدم الحالي لو موجودة
        $userBid = $auction->bids->firstWhere('user_id', $userId);
        
        // أعلى مزايدة
        $highestBid = $auction->bids->first();
        
        return $this->sendResponse(
            [
                // بيانات المزاد
                'id' => $auction->id,
                'title' => $auction->title,
                'description' => $auction->description,
                'location' => $this->getLocation($auction),
                'images' => $auction->images->map(fn($img) => $img->image_path),
                
                // الأسعار
                'starting_price' => (float) $auction->starting_price,
                'current_bid' => (float) ($auction->current_bid ?? $auction->starting_price),
                'min_accept_price' => (float) $auction->min_accept_price,
                
                // الوقت
                'time_remaining' => $auction->getTimeRemainingFormatted(),
                'starts_at' => $auction->starts_at?->toDateTimeString(),
                'ends_at' => $auction->ends_at?->toDateTimeString(),
                
                // الإحصائيات
                'views_count' => (int) $auction->views_count,
                'bids_count' => (int) $auction->bids_count,
                
                // أحدث المزايدات (آخر 10)
                'recent_bids' => $auction->bids->map(function ($bid) {
                    return [
                        'id' => $bid->id,
                        'amount' => (float) $bid->amount,
                        'user_name' => $bid->user?->name ?? 'مستخدم مجهول',
                        'created_at' => $bid->created_at?->diffForHumans(),
                        'is_winning' => $bid->is_winning,
                    ];
                }),
                
                // أعلى مزايدة
                'highest_bid' => $highestBid ? [
                    'id' => $highestBid->id,
                    'amount' => (float) $highestBid->amount,
                    'user_name' => $highestBid->user?->name ?? 'مستخدم مجهول',
                    'created_at' => $highestBid->created_at?->diffForHumans(),
                ] : null,
                
                // مزايدة المستخدم الحالي
                'my_bid' => $userBid ? [
                    'id' => $userBid->id,
                    'amount' => (float) $userBid->amount,
                    'is_winning' => $userBid->is_winning,
                    'created_at' => $userBid->created_at?->diffForHumans(),
                ] : null,
                
                // حالة مزايدتي
                'my_bid_status' => $userBid ? 
                    ($userBid->is_winning ? 'أعلى مزايد' : 'تم تجاوزك') : null,
                
                // بيانات البائع
                'advertiser' => $auction->isAdvertiserDepositPaid() ? [
                    'id' => $auction->user?->id,
                    'name' => $auction->user?->name,
                    'phone' => $auction->user?->phone,
                ] : null,
            ],
            'تم جلب تفاصيل المزاد بنجاح.'
        );
    }
    
    private function getLocation($auction): string
    {
        $parts = array_filter([
            $auction->country?->name,
            $auction->state?->name,
            $auction->city?->name,
        ]);
        return implode(', ', $parts);
    }

    private function recordView($auction): void
    {
        $user = Auth::user();
        \App\Models\AuctionView::firstOrCreate(
            [
                'auction_id' => $auction->id,
                'user_id' => $user?->id,
            ],
            [
                'ip_address' => request()->ip(),
                'viewed_at' => now(),
            ]
        );
    }

    /**
     * تعديل مزاد
     */
    public function update(UpdateAuctionRequest $request, int $id)
    {
        try {
            $auction = $this->auctionRepo->findById($id);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            $userId = Auth::id();

            if (!$this->auctionService->canUpdate($auction, $userId)) {
                return $this->sendError('لا يمكن تعديل هذا المزاد.', 403);
            }

            $data = $request->validated();
            $auction = $this->auctionService->update($auction, $data, $userId);

            return $this->sendResponse(
                new AuctionResource($auction),
                'تم تحديث المزاد بنجاح.'
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * إلغاء مزاد
     */
    public function cancel(int $id)
    {
        try {
            $auction = $this->auctionRepo->findById($id);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            $userId = Auth::id();

            $this->auctionService->cancelAuction($auction, $userId);

            return $this->sendResponse(
                [],
                'تم إلغاء المزاد بنجاح.'
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * إغلاق مزاد يدوياً
     */
    public function close(int $id)
    {
        try {
            $auction = $this->auctionRepo->findById($id);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            if ($auction->user_id !== Auth::id()) {
                return $this->sendError('لا يمكنك إغلاق هذا المزاد.', 403);
            }

            $this->auctionService->closeAuction($auction);

            return $this->sendResponse(
                [],
                'تم إغلاق المزاد بنجاح.'
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * مزاداتي (كبائع)
     */
    public function myAuctions(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $userId = Auth::id();

        $auctions = $this->auctionService->getMyAuctions($userId, $perPage);

        return $this->sendResponse(
            MyAuctionResource::collection($auctions),
            'تم جلب مزاداتك بنجاح.'
        );
    }

    /**
     * تحديد/تعديل قيمة التأمين (للأدمن فقط)
     */
    public function setDeposit(Request $request, int $id)
    {
        try {
            // التحقق من صلاحيات الأدمن
            if (Auth::user()?->role !== 'admin') {
                return $this->sendError('غير مصرح لك بتنفيذ هذا الإجراء.', 403);
            }

            $auction = $this->auctionRepo->findById($id);

            if (!$auction) {
                return $this->sendError('المزاد غير موجود.', 404);
            }

            // التحقق من البيانات
            $data = $request->validate([
                'deposit_type' => 'required|in:fixed,percentage',
                'deposit_fixed_amount' => 'required_if:deposit_type,fixed|nullable|numeric|min:1|max:999999.99',
                'deposit_percentage' => 'required_if:deposit_type,percentage|nullable|numeric|min:0.01|max:100',
            ]);

            // تحديث قيم التأمين
            $auctionData = [
                'deposit_type' => $data['deposit_type'],
                'deposit_fixed_amount' => $data['deposit_fixed_amount'] ?? null,
                'deposit_percentage' => $data['deposit_percentage'] ?? null,
            ];

            $this->auctionRepo->update($auction, $auctionData);

            return $this->sendResponse(
                [
                    'auction_id' => $auction->id,
                    'deposit_type' => $auctionData['deposit_type'],
                    'deposit_fixed_amount' => $auctionData['deposit_fixed_amount'],
                    'deposit_percentage' => $auctionData['deposit_percentage'],
                    'deposit_amount' => $auction->getDepositAmount(),
                ],
                'تم تحديد قيمة التأمين بنجاح.'
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
}

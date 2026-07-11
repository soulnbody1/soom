<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Application\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\ReviewPaymentSubmissionRequest;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Models\Auction\PaymentSubmission;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class PaymentSubmissionController extends Controller
{
    use ApiResponseTrait;

    public function index(AuctionIndexRequest $request): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return $this->sendError('Forbidden.', 403);
        }

        return $this->sendResponse(
            PaymentSubmissionResource::collection(
                PaymentSubmission::with(['auction', 'paymentMethod', 'deposit', 'settlement'])
                    ->latest('id')
                    ->paginate($request->perPage())
            ),
            'Payment submissions fetched.'
        );
    }

    public function review(
        ReviewPaymentSubmissionRequest $request,
        PaymentSubmission $paymentSubmission,
        ReviewPaymentSubmissionAction $action
    ): JsonResponse {
        $data = $request->validated();
        $submission = $data['action'] === 'approve'
            ? $action->approve($paymentSubmission, Auth::id(), (string) ($data['note'] ?? 'approved'))
            : $action->reject($paymentSubmission, Auth::id(), (string) $data['note']);

        return $this->sendResponse(new PaymentSubmissionResource($submission), 'Payment submission reviewed.');
    }
}

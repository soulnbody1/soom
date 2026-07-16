<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AdminPaymentSubmissionIndexRequest;
use App\Http\Requests\Auction\ReviewPaymentSubmissionRequest;
use App\Http\Resources\Auction\PaymentSubmissionResource;
use App\Models\Auction\PaymentSubmission;
use App\Services\Auction\Actions\ListPaymentSubmissionsAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class PaymentSubmissionController extends Controller
{
    use ApiResponseTrait;

    public function index(AdminPaymentSubmissionIndexRequest $request, ListPaymentSubmissionsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', PaymentSubmission::class);

        return $this->sendResponse(
            PaymentSubmissionResource::collection($action->execute($request->filters(), $request->perPage())),
            __('auction.messages.payment_submissions_fetched')
        );
    }

    public function review(
        ReviewPaymentSubmissionRequest $request,
        PaymentSubmission $paymentSubmission,
        ReviewPaymentSubmissionAction $action
    ): JsonResponse {
        $data = $request->validated();
        Gate::authorize($data['action'] === 'approve' ? 'approve' : 'reject', $paymentSubmission);
        if ($data['action'] === 'approve' && (bool) ($data['override_deadline'] ?? false)) {
            Gate::authorize('overrideDeadline', $paymentSubmission);
        }

        $submission = $data['action'] === 'approve'
            ? $action->approve(
                $paymentSubmission,
                Auth::id(),
                (string) ($data['note'] ?? 'approved'),
                (string) ($data['provider_transaction_id'] ?? ''),
                (bool) ($data['override_deadline'] ?? false),
                (string) ($data['override_reason'] ?? '')
            )
            : $action->reject($paymentSubmission, Auth::id(), (string) $data['note']);

        return $this->sendResponse(new PaymentSubmissionResource($submission), __('auction.messages.payment_submission_reviewed'));
    }

    public function receiptUrl(PaymentSubmission $paymentSubmission): JsonResponse
    {
        Gate::authorize('viewReceipt', $paymentSubmission);

        try {
            $url = Storage::disk($paymentSubmission->receipt_disk)
                ->temporaryUrl($paymentSubmission->receipt_path, now()->addMinutes(10));
        } catch (\Throwable) {
            return $this->sendError(__('auction.errors.receipt_url_unavailable'), 404);
        }

        return $this->sendResponse([
            'url' => $url,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], __('auction.messages.payment_receipt_url_created'));
    }
}

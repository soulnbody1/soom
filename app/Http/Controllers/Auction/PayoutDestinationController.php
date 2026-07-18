<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\PayoutDestinationRequest;
use App\Models\Auction\PayoutDestination;
use App\Repositories\Auction\PayoutDestinationRepository;
use App\Services\Auction\Actions\SavePayoutDestinationAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayoutDestinationController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, PayoutDestinationRepository $destinations): JsonResponse
    {
        $items = $destinations->listFor((int) $request->user()->id)
            ->map(fn (PayoutDestination $destination) => $this->payload($destination))
            ->values();

        return $this->sendResponse($items, __('auction.messages.payout_destinations_fetched'));
    }

    public function store(PayoutDestinationRequest $request, SavePayoutDestinationAction $action): JsonResponse
    {
        $destination = $action->execute((int) $request->user()->id, $request->validated());

        return $this->sendResponse($this->payload($destination), __('auction.messages.payout_destination_saved'), 201);
    }

    public function update(PayoutDestinationRequest $request, PayoutDestination $payoutDestination, SavePayoutDestinationAction $action): JsonResponse
    {
        if ((int) $payoutDestination->user_id !== (int) $request->user()->id) {
            return $this->sendError(__('auction.errors.payout_destination_not_found'), 404);
        }

        $destination = $action->execute((int) $request->user()->id, $request->validated(), $payoutDestination);

        return $this->sendResponse($this->payload($destination), __('auction.messages.payout_destination_saved'));
    }

    private function payload(PayoutDestination $destination): array
    {
        return [
            'id' => $destination->public_id,
            'recipient_name' => $destination->recipient_name,
            'identifier_type' => $destination->identifier_type,
            'identifier_value' => $destination->identifier_value,
            'is_default' => (bool) $destination->is_default,
            'created_at' => $destination->created_at?->toIso8601String(),
        ];
    }
}

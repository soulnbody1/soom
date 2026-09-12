<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\PayoutDestinationRequest;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PayoutDestination;
use App\Repositories\Auction\PayoutDestinationRepository;
use App\Services\Auction\Actions\ArchivePayoutDestinationAction;
use App\Services\Auction\Actions\SavePayoutDestinationAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'وجهات التحويل', description: 'حسابات التحويل التي يسجّلها البائع لاستلام مستحقاته.', weight: 8)]
final class PayoutDestinationController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض وجهات التحويل الخاصة بي',
        description: 'يعرض وجهات التحويل النشطة المسجّلة للمستخدم الحالي مع بيان الوجهة الافتراضية.'
    )]
    #[Response(200, description: 'قائمة وجهات التحويل.')]
    public function index(Request $request, PayoutDestinationRepository $destinations): JsonResponse
    {
        $items = $destinations->listFor((int) $request->user()->id)
            ->map(fn (PayoutDestination $destination) => $this->payload($destination))
            ->values();

        // The accepted identifier types travel with the list so a client renders
        // exactly what the store and update rules will accept, instead of keeping
        // its own copy that can drift out of step with PaymentMethod.
        return $this->sendResponse(
            $items,
            __('auction.messages.payout_destinations_fetched'),
            200,
            ['identifier_types' => PaymentMethod::IDENTIFIER_TYPES]
        );
    }

    #[Endpoint(
        title: 'إضافة وجهة تحويل',
        description: 'يسجّل وجهة تحويل جديدة للمستخدم الحالي، وتصبح الوجهة الافتراضية عند طلب ذلك.'
    )]
    #[Response(201, description: 'وجهة التحويل بعد حفظها.')]
    public function store(PayoutDestinationRequest $request, SavePayoutDestinationAction $action): JsonResponse
    {
        $destination = $action->execute((int) $request->user()->id, $request->validated());

        return $this->sendResponse($this->payload($destination), __('auction.messages.payout_destination_saved'), 201);
    }

    #[Endpoint(
        title: 'تعديل وجهة تحويل',
        description: 'يعدّل بيانات وجهة تحويل يملكها المستخدم الحالي. يُرجع 404 إذا كانت الوجهة تخص مستخدمًا آخر.'
    )]
    #[PathParameter('payoutDestination', description: 'المعرّف العام لوجهة التحويل (ULID).')]
    #[Response(200, description: 'وجهة التحويل بعد التعديل.')]
    public function update(PayoutDestinationRequest $request, PayoutDestination $payoutDestination, SavePayoutDestinationAction $action): JsonResponse
    {
        if ((int) $payoutDestination->user_id !== (int) $request->user()->id) {
            return $this->sendError(__('auction.errors.payout_destination_not_found'), 404, 'payout_destination_not_found');
        }

        $destination = $action->execute((int) $request->user()->id, $request->validated(), $payoutDestination);

        return $this->sendResponse($this->payload($destination), __('auction.messages.payout_destination_saved'));
    }

    #[Endpoint(
        title: 'أرشفة وجهة تحويل',
        description: 'يؤرشف وجهة التحويل فلا تظهر في القائمة ولا تُستخدم في عمليات الصرف اللاحقة، مع الإبقاء عليها في سجلات الصرف السابقة. يُرجع 404 إذا كانت الوجهة تخص مستخدمًا آخر.'
    )]
    #[PathParameter('payoutDestination', description: 'المعرّف العام لوجهة التحويل (ULID).')]
    #[Response(200, description: 'استجابة بلا محتوى تؤكد الأرشفة.')]
    public function destroy(
        Request $request,
        PayoutDestination $payoutDestination,
        ArchivePayoutDestinationAction $action
    ): JsonResponse {
        if ((int) $payoutDestination->user_id !== (int) $request->user()->id) {
            return $this->sendError(__('auction.errors.payout_destination_not_found'), 404, 'payout_destination_not_found');
        }

        $action->execute($payoutDestination, (int) $request->user()->id);

        return $this->sendEmptyResponse(__('auction.messages.payout_destination_archived'));
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

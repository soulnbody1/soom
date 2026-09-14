<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SupportConversationRequest;
use App\Http\Resources\Support\SupportMessageResource;
use App\Http\Resources\Support\SupportTicketResource;
use App\Models\Support\SupportTicket;
use App\Repositories\Support\Queries\SupportConversationQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'إدارة الدعم الفني', description: 'طابور تذاكر الدعم ومحادثاتها داخل لوحة التحكم.', weight: 2)]
final class AdminSupportConversationController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(title: 'مزامنة محادثة الدعم للإدارة', description: 'يعرض التذكرة وأحدث الرسائل العامة والملاحظات الداخلية في طلب واحد، أو يعيد التغييرات بعد ULID رسالة محددة.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[QueryParameter('after_message_id', description: 'ULID آخر رسالة موجودة في لوحة التحكم.')]
    #[QueryParameter('known_version', description: 'نسخة التذكرة الحالية في لوحة التحكم.')]
    #[QueryParameter('limit', description: 'الحد الأقصى للرسائل، افتراضيًا 50 وبحد أقصى 100.')]
    #[Response(200, description: 'التذكرة ومحادثتها الكاملة وحالة المزامنة.')]
    #[Response(404, description: 'التذكرة أو مؤشر الرسالة غير موجود.')]
    public function __invoke(
        SupportConversationRequest $request,
        SupportTicket $ticket,
        SupportConversationQuery $conversation,
    ): JsonResponse {
        $after = $request->validated('after_message_id');
        $result = $conversation->execute($ticket, $after, $request->limit(), true);
        $messages = $result['messages'];
        $ticket->load(['category', 'assignee', 'requester']);
        $knownVersion = $request->validated('known_version');

        return $this->sendResponse([
            'ticket' => (new SupportTicketResource($ticket))->resolve($request),
            'messages' => SupportMessageResource::collection($messages)->resolve($request),
            'sync' => [
                'version' => (int) $ticket->version,
                'latest_message_id' => $messages->last()?->public_id ?? $after,
                'changed' => $knownVersion === null || (int) $knownVersion !== (int) $ticket->version || $messages->isNotEmpty(),
                'has_more_before' => $result['has_more_before'],
                'has_more_after' => $result['has_more_after'],
            ],
        ], 'تمت مزامنة محادثة الدعم للإدارة بنجاح.');
    }
}

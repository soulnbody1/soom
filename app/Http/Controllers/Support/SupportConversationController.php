<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

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
use Illuminate\Support\Facades\Gate;

#[Group(name: 'مركز الدعم', description: 'التذاكر ومحادثات الدعم الفني للمستخدم وتطبيق الهاتف.', weight: 8)]
final class SupportConversationController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(title: 'مزامنة محادثة الدعم', description: 'يعرض التذكرة وأحدث رسائلها العامة في طلب واحد، أو الرسائل الأحدث من ULID محدد للمزامنة التدريجية دون إعادة تحميل سجل المحادثة كاملًا.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[QueryParameter('after_message_id', description: 'ULID آخر رسالة موجودة لدى العميل لجلب الرسائل الأحدث فقط.')]
    #[QueryParameter('known_version', description: 'نسخة التذكرة الموجودة لدى العميل لتحديد ما إذا تغيرت حالتها.')]
    #[QueryParameter('limit', description: 'الحد الأقصى للرسائل، افتراضيًا 50 وبحد أقصى 100.')]
    #[Response(200, description: 'التذكرة والرسائل العامة وحالة المزامنة.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(404, description: 'التذكرة أو مؤشر الرسالة غير موجود.')]
    public function __invoke(SupportConversationRequest $request, SupportTicket $ticket, SupportConversationQuery $conversation): JsonResponse
    {
        Gate::authorize('view', $ticket);
        $after = $request->validated('after_message_id');
        $result = $conversation->execute($ticket, $after, $request->limit(), false);
        $messages = $result['messages'];

        $ticket->load(['category', 'assignee']);
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
        ], 'تمت مزامنة محادثة الدعم بنجاح.');
    }
}

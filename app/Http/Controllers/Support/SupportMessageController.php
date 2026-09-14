<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Domain\Support\Enums\SupportMessageVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CreateSupportMessageRequest;
use App\Http\Requests\Support\ListSupportMessagesRequest;
use App\Http\Resources\Support\SupportMessageResource;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Services\Support\SupportTicketService;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'مركز الدعم', description: 'التذاكر ومحادثات الدعم الفني للمستخدم وتطبيق الهاتف.', weight: 8)]
final class SupportMessageController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly SupportTicketService $service) {}

    #[Endpoint(title: 'عرض رسائل تذكرة الدعم', description: 'يعرض الرسائل العامة فقط لتذكرة يملكها المستخدم الحالي. الملاحظات الداخلية غير قابلة للظهور في هذا المسار. استخدم before_id لتحميل الرسائل الأقدم.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[QueryParameter('before_id', description: 'المعرّف العام ULID لأقدم رسالة محمّلة لجلب ما قبلها.')]
    #[QueryParameter('per_page', description: 'عدد الرسائل، والقيمة الافتراضية 50 والحد الأقصى 100.')]
    #[Response(200, description: 'رسائل التذكرة العامة من الأحدث إلى الأقدم.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function index(ListSupportMessagesRequest $request, SupportTicket $ticket): AnonymousResourceCollection
    {
        Gate::authorize('view', $ticket);
        $before = $request->validated('before_id');
        $cursorId = $before === null ? null : SupportMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('visibility', SupportMessageVisibility::Public->value)
            ->where('public_id', $before)
            ->value('id');

        if ($before !== null && $cursorId === null) {
            abort(404);
        }

        $messages = SupportMessage::query()->with('author')->where('ticket_id', $ticket->id)
            ->where('visibility', SupportMessageVisibility::Public->value)
            ->when($cursorId, fn ($query, $id) => $query->where('id', '<', $id))
            ->orderByDesc('id')->paginate($request->perPage());

        return SupportMessageResource::collection($messages)->additional(['success' => true, 'message' => 'تم جلب رسائل الدعم بنجاح.']);
    }

    #[Endpoint(title: 'إرسال رسالة إلى الدعم', description: 'يرسل رسالة عامة جديدة داخل تذكرة المستخدم. يعيد فتح التذكرة تلقائيًا إذا كانت بانتظار العميل أو محلولة، ويمنع الإرسال إلى التذكرة المغلقة.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[BodyParameter('message', description: 'نص الرسالة بحد أقصى 5000 حرف.', required: true, type: 'string', example: 'ما زالت المشكلة قائمة بعد إعادة المحاولة.')]
    #[BodyParameter('client_message_id', description: 'UUID اختياري لتحقيق idempotency ومنع تكرار الرسالة.', type: 'string')]
    #[Response(201, description: 'تم إنشاء الرسالة وإرجاع بياناتها.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(422, description: 'الرسالة غير صحيحة أو التذكرة مغلقة.')]
    #[Response(429, description: 'تم تجاوز حد إرسال الرسائل.')]
    public function store(CreateSupportMessageRequest $request, SupportTicket $ticket): JsonResponse
    {
        Gate::authorize('reply', $ticket);

        return $this->sendResponse(new SupportMessageResource($this->service->addCustomerMessage($ticket, $request->user(), $request->validated())), 'تم إرسال رسالتك إلى الدعم.', 201);
    }
}

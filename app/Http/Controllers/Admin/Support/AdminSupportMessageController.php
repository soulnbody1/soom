<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

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

#[Group(name: 'إدارة الدعم الفني', description: 'طابور تذاكر الدعم وإسنادها وتغيير حالتها وأولويتها والرد عليها من لوحة التحكم.', weight: 2)]
final class AdminSupportMessageController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly SupportTicketService $service) {}

    #[Endpoint(title: 'عرض محادثة التذكرة للموظف', description: 'يعرض الرسائل العامة والملاحظات الداخلية للموظف فقط، مرتبة من الأحدث إلى الأقدم مع تحميل تدريجي باستخدام before_id.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[QueryParameter('before_id', description: 'المعرّف العام ULID لأقدم رسالة محمّلة لجلب ما قبلها.')]
    #[QueryParameter('per_page', description: 'عدد الرسائل، والقيمة الافتراضية 50 والحد الأقصى 100.')]
    #[Response(200, description: 'محادثة التذكرة شاملة الملاحظات الداخلية.')]
    #[Response(403, description: 'الحساب ليس مديرًا.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function index(ListSupportMessagesRequest $request, SupportTicket $ticket): AnonymousResourceCollection
    {
        $before = $request->validated('before_id');
        $cursorId = $before === null ? null : SupportMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('public_id', $before)
            ->value('id');

        if ($before !== null && $cursorId === null) {
            abort(404);
        }

        $messages = SupportMessage::query()->with('author')->where('ticket_id', $ticket->id)
            ->when($cursorId, fn ($query, $id) => $query->where('id', '<', $id))
            ->orderByDesc('id')->paginate($request->perPage());

        return SupportMessageResource::collection($messages)->additional(['success' => true, 'message' => 'تم جلب محادثة الدعم بنجاح.']);
    }

    #[Endpoint(title: 'إرسال رد عام إلى العميل', description: 'يرسل ردًا يظهر لصاحب التذكرة، ويسند التذكرة تلقائيًا للموظف الحالي إذا لم تكن مسندة، ويسجل أول استجابة ويحوّل الحالة إلى بانتظار العميل.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[BodyParameter('message', description: 'نص الرد العام بحد أقصى 5000 حرف.', required: true, type: 'string', example: 'راجعنا العملية وجارٍ معالجتها الآن.')]
    #[BodyParameter('client_message_id', description: 'UUID اختياري لمنع تكرار الرد.', type: 'string')]
    #[Response(201, description: 'تم إنشاء الرد العام.')]
    #[Response(422, description: 'الرسالة غير صحيحة أو التذكرة مغلقة.')]
    public function store(CreateSupportMessageRequest $request, SupportTicket $ticket): JsonResponse
    {
        return $this->sendResponse(new SupportMessageResource($this->service->addAgentMessage($ticket, $request->user(), $request->validated(), false)), 'تم إرسال الرد إلى العميل.', 201);
    }

    #[Endpoint(title: 'إضافة ملاحظة داخلية', description: 'يضيف ملاحظة تشغيلية خاصة بموظفي الدعم. لا تظهر هذه الملاحظة في API المستخدم أو قناة التذكرة الخاصة به ولا تغيّر آخر رسالة عامة.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[BodyParameter('message', description: 'نص الملاحظة الداخلية بحد أقصى 5000 حرف.', required: true, type: 'string', example: 'تم التصعيد إلى فريق المدفوعات.')]
    #[BodyParameter('client_message_id', description: 'UUID اختياري لمنع التكرار.', type: 'string')]
    #[Response(201, description: 'تم حفظ الملاحظة الداخلية.')]
    #[Response(422, description: 'الملاحظة غير صحيحة أو التذكرة مغلقة.')]
    public function internalNote(CreateSupportMessageRequest $request, SupportTicket $ticket): JsonResponse
    {
        return $this->sendResponse(new SupportMessageResource($this->service->addAgentMessage($ticket, $request->user(), $request->validated(), true)), 'تمت إضافة الملاحظة الداخلية.', 201);
    }
}

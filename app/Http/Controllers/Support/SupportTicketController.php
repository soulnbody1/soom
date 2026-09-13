<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CreateSupportTicketRequest;
use App\Http\Requests\Support\ListSupportTicketsRequest;
use App\Http\Resources\Support\SupportTicketResource;
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
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'مركز الدعم', description: 'التذاكر ومحادثات الدعم الفني للمستخدم وتطبيق الهاتف.', weight: 8)]
final class SupportTicketController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly SupportTicketService $service) {}

    #[Endpoint(title: 'عرض تذاكر الدعم الخاصة بي', description: 'يعرض تذاكر المستخدم الحالي فقط من الأحدث نشاطًا إلى الأقدم، مع الحالة والأولوية والتصنيف ومؤشرات اتفاقية مستوى الخدمة.')]
    #[QueryParameter('status', description: 'تصفية اختيارية حسب الحالة: new أو open أو waiting_customer أو on_hold أو resolved أو closed.')]
    #[QueryParameter('page', description: 'رقم الصفحة، والقيمة الافتراضية 1.')]
    #[QueryParameter('per_page', description: 'عدد النتائج، والقيمة الافتراضية 20 والحد الأقصى 50.')]
    #[Response(200, description: 'تذاكر المستخدم مقسّمة إلى صفحات.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    #[Response(422, description: 'معاملات التصفية غير صحيحة.')]
    public function index(ListSupportTicketsRequest $request): AnonymousResourceCollection
    {
        $tickets = SupportTicket::query()->with(['category', 'assignee'])
            ->where('requester_id', $request->user()->id)
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('last_message_at')->paginate($request->perPage());

        return SupportTicketResource::collection($tickets)->additional(['success' => true, 'message' => 'تم جلب تذاكر الدعم بنجاح.']);
    }

    #[Endpoint(title: 'إنشاء تذكرة دعم', description: 'ينشئ تذكرة جديدة للمستخدم الحالي ورسالتها الأولى في عملية واحدة، ويحدد مواعيد SLA من إعدادات التصنيف. يمكن تمرير client_message_id لمنع التكرار عند إعادة المحاولة من الهاتف.')]
    #[BodyParameter('category_id', description: 'المعرّف العام ULID لتصنيف الدعم.', required: true, type: 'string', example: '01K5Z7J39X5D3M8YF8Z8M2P7Q1')]
    #[BodyParameter('subject', description: 'عنوان موجز للتذكرة بحد أقصى 160 حرفًا.', required: true, type: 'string', example: 'تعذر إتمام عملية الدفع')]
    #[BodyParameter('message', description: 'تفاصيل الطلب بحد أقصى 5000 حرف.', required: true, type: 'string', example: 'تم خصم المبلغ ولم تتغير حالة العملية.')]
    #[BodyParameter('client_message_id', description: 'UUID اختياري لمنع إنشاء الرسالة مرتين عند إعادة الطلب.', type: 'string')]
    #[BodyParameter('context_type', description: 'نوع العنصر المرتبط اختياريًا: auction أو ad أو payment أو account.', type: 'string')]
    #[BodyParameter('context_id', description: 'معرّف العنصر المرتبط اختياريًا.', type: 'string')]
    #[Response(201, description: 'تم إنشاء التذكرة مع رقم مرجعي ومواعيد SLA.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    #[Response(404, description: 'تصنيف الدعم غير موجود أو غير نشط.')]
    #[Response(422, description: 'بيانات التذكرة غير صحيحة.')]
    #[Response(429, description: 'تم تجاوز حد إنشاء التذاكر.')]
    public function store(CreateSupportTicketRequest $request): JsonResponse
    {
        return $this->sendResponse(new SupportTicketResource($this->service->create($request->user(), $request->validated())), 'تم إنشاء تذكرة الدعم بنجاح.', 201);
    }

    #[Endpoint(title: 'عرض تفاصيل تذكرة دعم', description: 'يعرض بيانات تذكرة يملكها المستخدم الحالي. الرسائل تُجلب من مسار الرسائل المخصص لتدعم التحميل التدريجي.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[Response(200, description: 'تفاصيل التذكرة وحالتها الحالية.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        return $this->sendResponse(new SupportTicketResource($ticket->load(['category', 'assignee'])), 'تم جلب تذكرة الدعم بنجاح.');
    }

    #[Endpoint(title: 'اعتبار التذكرة محلولة', description: 'يسمح لصاحب التذكرة باعتبار طلبه محلولًا مع الاحتفاظ بإمكانية إعادة فتحه خلال المدة المحددة.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[Response(200, description: 'تم تحويل التذكرة إلى محلولة.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function resolve(Request $request, SupportTicket $ticket): JsonResponse
    {
        Gate::authorize('reply', $ticket);

        return $this->sendResponse(new SupportTicketResource($this->service->resolve($ticket, $request->user())), 'تم اعتبار التذكرة محلولة.');
    }

    #[Endpoint(title: 'إعادة فتح تذكرة', description: 'يعيد فتح تذكرة محلولة تخص المستخدم الحالي إذا كانت ما زالت داخل نافذة إعادة الفتح المسموح بها.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[Response(200, description: 'تمت إعادة فتح التذكرة.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    #[Response(422, description: 'التذكرة ليست محلولة أو انتهت نافذة إعادة الفتح.')]
    public function reopen(Request $request, SupportTicket $ticket): JsonResponse
    {
        Gate::authorize('reply', $ticket);

        return $this->sendResponse(new SupportTicketResource($this->service->reopen($ticket, $request->user())), 'تمت إعادة فتح التذكرة.');
    }
}

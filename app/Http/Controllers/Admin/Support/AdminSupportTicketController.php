<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Domain\Support\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ListSupportTicketsRequest;
use App\Http\Requests\Support\UpdateSupportTicketRequest;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group(name: 'إدارة الدعم الفني', description: 'طابور تذاكر الدعم وإسنادها وتغيير حالتها وأولويتها والرد عليها من لوحة التحكم.', weight: 2)]
final class AdminSupportTicketController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly SupportTicketService $service) {}

    #[Endpoint(title: 'عرض طابور تذاكر الدعم', description: 'يعرض تذاكر الدعم لكل المستخدمين مع بحث وفلاتر للحالة والأولوية والمسؤول ومخاطر SLA. النتائج مرتبة حسب آخر نشاط ومقسّمة إلى صفحات.')]
    #[QueryParameter('status', description: 'الحالة المطلوبة: new أو open أو waiting_customer أو on_hold أو resolved أو closed.')]
    #[QueryParameter('priority', description: 'الأولوية المطلوبة: low أو normal أو high أو urgent.')]
    #[QueryParameter('assigned_to', description: 'معرّف موظف الدعم المسؤول.')]
    #[QueryParameter('unassigned', description: 'مرر true لعرض التذاكر غير المسندة.')]
    #[QueryParameter('sla', description: 'breached للمتأخرة أو risk التي يحين موعدها خلال ساعة.')]
    #[QueryParameter('search', description: 'بحث في الرقم المرجعي أو العنوان أو اسم صاحب التذكرة.')]
    #[QueryParameter('page', description: 'رقم الصفحة، والقيمة الافتراضية 1.')]
    #[QueryParameter('per_page', description: 'عدد النتائج، والقيمة الافتراضية 20 والحد الأقصى 50.')]
    #[Response(200, description: 'طابور التذاكر مقسّم إلى صفحات.')]
    #[Response(401, description: 'الموظف غير مسجل الدخول.')]
    #[Response(403, description: 'الحساب ليس مديرًا.')]
    #[Response(422, description: 'معاملات التصفية غير صحيحة.')]
    public function index(ListSupportTicketsRequest $request): AnonymousResourceCollection
    {
        $tickets = $this->query($request)->paginate($request->perPage());

        return SupportTicketResource::collection($tickets)->additional(['success' => true, 'message' => 'تم جلب طابور الدعم بنجاح.']);
    }

    #[Endpoint(title: 'ملخص تشغيل الدعم', description: 'يعرض أعداد التذاكر حسب الطوابير الأساسية مع عدد التذاكر المتأخرة عن SLA لاستخدامه في بطاقات ولوحة مراقبة الدعم.')]
    #[Response(200, description: 'أعداد الطوابير ومخالفات SLA محسوبة لحظيًا.')]
    #[Response(403, description: 'الحساب ليس مديرًا.')]
    public function summary(): JsonResponse
    {
        $counts = SupportTicket::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $active = [SupportTicketStatus::New->value, SupportTicketStatus::Open->value, SupportTicketStatus::WaitingCustomer->value, SupportTicketStatus::OnHold->value];

        return $this->sendResponse([
            'total_active' => SupportTicket::query()->whereIn('status', $active)->count(),
            'new' => (int) ($counts[SupportTicketStatus::New->value] ?? 0),
            'open' => (int) ($counts[SupportTicketStatus::Open->value] ?? 0),
            'waiting_customer' => (int) ($counts[SupportTicketStatus::WaitingCustomer->value] ?? 0),
            'unassigned' => SupportTicket::query()->whereNull('assigned_to')->whereIn('status', $active)->count(),
            'urgent' => SupportTicket::query()->where('priority', 'urgent')->whereIn('status', $active)->count(),
            'sla_breached' => SupportTicket::query()->whereIn('status', $active)->where(function (Builder $query): void {
                $query->where(fn (Builder $q) => $q->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))
                    ->orWhere(fn (Builder $q) => $q->whereNull('resolved_at')->where('resolution_due_at', '<', now()));
            })->count(),
        ], 'تم جلب ملخص الدعم بنجاح.');
    }

    #[Endpoint(title: 'عرض تذكرة للموظف', description: 'يعرض بيانات التذكرة كاملة مع صاحبها وتصنيفها والمسؤول عنها ومؤشرات SLA. الرسائل تُجلب من مسار مستقل لدعم التحميل التدريجي.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[Response(200, description: 'تفاصيل التذكرة الإدارية.')]
    #[Response(403, description: 'الحساب ليس مديرًا.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function show(SupportTicket $ticket): JsonResponse
    {
        return $this->sendResponse(new SupportTicketResource($ticket->load(['category', 'requester', 'assignee'])), 'تم جلب تذكرة الدعم بنجاح.');
    }

    #[Endpoint(title: 'تحديث تشغيل التذكرة', description: 'يغيّر الحالة أو الأولوية أو الموظف المسؤول. expected_version إلزامي لمنع الكتابة فوق تعديل متزامن أجراه موظف آخر، وكل تغيير يسجل في سجل التدقيق.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[BodyParameter('expected_version', description: 'نسخة التذكرة التي يعرضها العميل حاليًا.', required: true, type: 'integer', example: 3)]
    #[BodyParameter('status', description: 'الحالة الجديدة اختياريًا.', type: 'string', example: 'open')]
    #[BodyParameter('priority', description: 'الأولوية الجديدة اختياريًا.', type: 'string', example: 'high')]
    #[BodyParameter('assigned_to', description: 'معرّف المدير المسؤول أو null لإلغاء الإسناد.', type: 'integer')]
    #[Response(200, description: 'تم التحديث ورفع رقم النسخة.')]
    #[Response(404, description: 'التذكرة أو الموظف غير موجود.')]
    #[Response(422, description: 'القيم غير صحيحة أو النسخة قديمة بسبب تعديل متزامن.')]
    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        return $this->sendResponse(new SupportTicketResource($this->service->update($ticket, $request->user(), $request->validated())), 'تم تحديث تذكرة الدعم.');
    }

    private function query(ListSupportTicketsRequest $request): Builder
    {
        return SupportTicket::query()->with(['category', 'requester', 'assignee'])
            ->when($request->validated('status'), fn (Builder $query, $value) => $query->where('status', $value))
            ->when($request->validated('priority'), fn (Builder $query, $value) => $query->where('priority', $value))
            ->when($request->validated('assigned_to'), fn (Builder $query, $value) => $query->where('assigned_to', $value))
            ->when($request->boolean('unassigned'), fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when($request->validated('search'), function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhereHas('requester', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->validated('sla') === 'breached', fn (Builder $query) => $query->where(function (Builder $sla): void {
                $sla->where(fn (Builder $q) => $q->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))
                    ->orWhere(fn (Builder $q) => $q->whereNull('resolved_at')->where('resolution_due_at', '<', now()));
            }))
            ->when($request->validated('sla') === 'risk', fn (Builder $query) => $query->where(function (Builder $sla): void {
                $sla->where(fn (Builder $q) => $q->whereNull('first_responded_at')->whereBetween('first_response_due_at', [now(), now()->addHour()]))
                    ->orWhere(fn (Builder $q) => $q->whereNull('resolved_at')->whereBetween('resolution_due_at', [now(), now()->addHour()]));
            }))
            ->orderByDesc('last_message_at');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\SupportTicket;
use App\Services\Support\SupportTicketService;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'مركز الدعم', description: 'التذاكر ومحادثات الدعم الفني للمستخدم وتطبيق الهاتف.', weight: 8)]
final class SupportReadController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(title: 'تسجيل قراءة تذكرة الدعم', description: 'يسجل أن المستخدم الحالي قرأ أحدث رسالة عامة في التذكرة، ويستخدم لحساب مؤشرات الرسائل غير المقروءة بدقة عبر الأجهزة.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[Response(200, description: 'تم تحديث مؤشر القراءة.')]
    #[Response(403, description: 'التذكرة لا تخص المستخدم الحالي.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function __invoke(Request $request, SupportTicket $ticket, SupportTicketService $service): JsonResponse
    {
        Gate::authorize('view', $ticket);
        $read = $service->markRead($ticket, $request->user());

        return $this->sendResponse(['last_read_message_id' => $read->last_read_message_id, 'read_at' => $read->read_at?->toIso8601String()], 'تم تسجيل قراءة التذكرة.');
    }
}

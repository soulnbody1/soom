<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

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

#[Group(name: 'إدارة الدعم الفني', description: 'طابور تذاكر الدعم وإسنادها وتغيير حالتها وأولويتها والرد عليها من لوحة التحكم.', weight: 2)]
final class AdminSupportReadController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(title: 'تسجيل قراءة الموظف للتذكرة', description: 'يسجل أن موظف الدعم الحالي قرأ أحدث رسالة في التذكرة، بما فيها الملاحظات الداخلية، لحساب حالة عدم القراءة لكل موظف بصورة مستقلة.')]
    #[PathParameter('ticket', description: 'المعرّف العام ULID للتذكرة.')]
    #[Response(200, description: 'تم تحديث مؤشر قراءة الموظف.')]
    #[Response(403, description: 'الحساب ليس مديرًا.')]
    #[Response(404, description: 'التذكرة غير موجودة.')]
    public function __invoke(Request $request, SupportTicket $ticket, SupportTicketService $service): JsonResponse
    {
        $read = $service->markRead($ticket, $request->user());

        return $this->sendResponse(['last_read_message_id' => $read->last_read_message_id, 'read_at' => $read->read_at?->toIso8601String()], 'تم تسجيل قراءة التذكرة.');
    }
}

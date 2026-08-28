<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\SaveSupportContactRequest;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\SupportContactResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'التواصل مع الدعم', description: 'قنوات التواصل مع دعم المزادات المعروضة للمستخدمين وإدارتها من المشرف.', weight: 16)]
final class SupportContactController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly SupportContactResolver $contacts,
    ) {}

    #[Endpoint(
        title: 'عرض بيانات التواصل مع الدعم',
        description: 'يعرض قنوات التواصل مع فريق الدعم وأوقات العمل كما تظهر للمستخدم. تُقرأ القيم من الإعدادات المحفوظة في قاعدة البيانات، وعند غياب أي قناة يُرجع النظام قيمة بيئة التشغيل المقابلة لها، فإن لم تكن مهيّأة أُرجعت القيمة null.'
    )]
    #[Response(200, description: 'قنوات التواصل مع الدعم وأوقات العمل.')]
    public function show(): JsonResponse
    {
        return $this->sendResponse($this->contacts->resolved(), __('auction.messages.support_contact_fetched'));
    }

    #[Endpoint(
        title: 'عرض بيانات الدعم للتحرير',
        description: 'يعرض القيم المحفوظة في قاعدة البيانات إلى جانب القيم النهائية التي يراها المستخدم بعد تطبيق قيم بيئة التشغيل الاحتياطية، ليتمكن المشرف من التمييز بين ما حُفظ فعلًا وما جاء من الإعدادات.'
    )]
    #[Response(200, description: 'القيم المحفوظة والقيم النهائية الظاهرة للمستخدم وتاريخ آخر تعديل.')]
    public function edit(): JsonResponse
    {
        return $this->sendResponse($this->adminPayload(), __('auction.messages.support_contact_fetched'));
    }

    #[Endpoint(
        title: 'حفظ بيانات التواصل مع الدعم',
        description: 'يحفظ قنوات التواصل مع الدعم في قاعدة البيانات فتظهر للمستخدمين فورًا دون تعديل بيئة التشغيل أو إعادة نشر. إرسال قيمة فارغة لأي قناة يمسح القيمة المحفوظة ويعيد القناة إلى قيمة بيئة التشغيل إن وُجدت.'
    )]
    #[Response(200, description: 'القيم بعد الحفظ مع القيم النهائية الظاهرة للمستخدم.')]
    public function store(SaveSupportContactRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $this->contacts->save($request->validated(), $request->user());

        return $this->sendResponse($this->adminPayload(), __('auction.messages.support_contact_saved'));
    }

    private function adminPayload(): array
    {
        Gate::authorize('viewAny', Auction::class);

        $record = $this->contacts->record();
        $record?->loadMissing('editor:id,name');

        $stored = [];
        foreach (SupportContactResolver::CHANNELS as $channel) {
            $stored[$channel] = $record?->{$channel};
        }

        return [
            'stored' => $stored,
            'effective' => $this->contacts->resolved(),
            'updated_at' => $record?->updated_at?->toIso8601String(),
            'updated_by' => $record?->editor ? [
                'id' => $record->editor->id,
                'name' => $record->editor->name,
            ] : null,
        ];
    }
}

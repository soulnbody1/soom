<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportAgentResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group(name: 'إدارة الدعم الفني', description: 'طابور تذاكر الدعم وإسنادها وتغيير حالتها وأولويتها والرد عليها من لوحة التحكم.', weight: 2)]
final class AdminSupportAgentController extends Controller
{
    #[Endpoint(title: 'عرض موظفي الدعم المتاحين', description: 'يعرض الحد الأدنى من بيانات حسابات المديرين النشطة لاستخدامها في قائمة إسناد التذكرة، دون إرجاع بيانات اتصال أو صلاحيات حساسة.')]
    #[Response(200, description: 'أسماء ومعرّفات الموظفين المتاحين للإسناد.')]
    #[Response(401, description: 'الموظف غير مسجل الدخول.')]
    #[Response(403, description: 'الحساب ليس مديرًا.')]
    public function __invoke(): AnonymousResourceCollection
    {
        return SupportAgentResource::collection(User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']))
            ->additional(['success' => true, 'message' => 'تم جلب موظفي الدعم بنجاح.']);
    }
}

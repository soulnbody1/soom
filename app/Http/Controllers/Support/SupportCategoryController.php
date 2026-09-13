<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportCategoryResource;
use App\Models\Support\SupportCategory;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group(name: 'مركز الدعم', description: 'التذاكر ومحادثات الدعم الفني للمستخدم وتطبيق الهاتف.', weight: 8)]
final class SupportCategoryController extends Controller
{
    #[Endpoint(title: 'عرض تصنيفات الدعم', description: 'يعرض تصنيفات الدعم النشطة المرتبة لاستخدامها عند إنشاء تذكرة جديدة، مع الاسم العربي والإنجليزي والأولوية الافتراضية.')]
    #[Response(200, description: 'تصنيفات الدعم النشطة.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    public function __invoke(): AnonymousResourceCollection
    {
        return SupportCategoryResource::collection(SupportCategory::query()->where('is_active', true)->orderBy('sort_order')->get())
            ->additional(['success' => true, 'message' => 'تم جلب تصنيفات الدعم بنجاح.']);
    }
}

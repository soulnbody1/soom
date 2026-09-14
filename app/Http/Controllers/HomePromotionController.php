<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\BannerResource;
use App\Http\Resources\CharitySystemResource;
use App\Services\AnnouncementService;
use App\Services\BannerService;
use App\Services\CharitySystemService;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'المحتوى العام', description: 'المحتوى العام المعروض في واجهات الاكتشاف.', weight: 7)]
final class HomePromotionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly BannerService $banners,
        private readonly AnnouncementService $announcements,
        private readonly CharitySystemService $charity,
    ) {}

    #[Endpoint(title: 'عرض محتوى الصفحة الرئيسية الترويجي', description: 'يجمع البنرات والإعلانات النصية وحملات الخير النشطة التي تستخدمها الصفحة الرئيسية في استجابة واحدة قابلة للتخزين المؤقت.')]
    #[Response(200, description: 'المحتوى الترويجي النشط مقسم حسب نوعه.')]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->sendResponse([
            'banners' => BannerResource::collection($this->banners->allActive())->resolve($request),
            'announcements' => AnnouncementResource::collection($this->announcements->allActive())->resolve($request),
            'charity' => CharitySystemResource::collection($this->charity->allActive())->resolve($request),
        ], 'تم جلب محتوى الصفحة الرئيسية بنجاح.');
    }
}

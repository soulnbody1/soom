<?php

namespace App\Http\Controllers;

use App\Http\Requests\BannerRequest;
use App\Http\Requests\UpdateBannerRequest;
use App\Http\Resources\BannerResource;
use App\Services\BannerService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group(name: 'اللافتات الإعلانية', description: 'اللافتات الترويجية المعروضة للمستخدمين وإدارتها من لوحة التحكم.', weight: 17)]
class BannerController extends Controller
{
    protected $service;

    public function __construct(BannerService $service)
    {
        $this->service = $service;
    }

    #[Endpoint(title: 'عرض اللافتات النشطة', description: 'يعرض اللافتات الإعلانية النشطة والمتاحة حاليًا للمستخدمين.')]
    #[Response(200, description: 'قائمة اللافتات الإعلانية النشطة.')]
    public function index()
    {
        $banners = $this->service->allActive();

        return BannerResource::collection($banners);
    }

    #[Endpoint(title: 'عرض جميع اللافتات للإدارة', description: 'يعرض جميع اللافتات الإعلانية داخل لوحة الإدارة بغض النظر عن حالتها.')]
    #[Response(200, description: 'قائمة اللافتات الإعلانية الكاملة.')]
    public function indexforadmin()
    {
        $banners = $this->service->list();

        return BannerResource::collection($banners);
    }

    #[Endpoint(title: 'إنشاء لافتة إعلانية', description: 'ينشئ لافتة جديدة بالصورة والرابط والفترة والترتيب المحدد.')]
    #[Response(200, description: 'بيانات اللافتة بعد إنشائها.')]
    public function store(BannerRequest $request)
    {
        $banner = $this->service->create($request->validated());

        return new BannerResource($banner);
    }

    #[Endpoint(title: 'عرض لافتة للإدارة', description: 'يعرض تفاصيل لافتة إعلانية محددة داخل لوحة الإدارة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للافتة.')]
    #[Response(200, description: 'بيانات اللافتة المطلوبة.')]
    public function show($id)
    {
        $banner = $this->service->show($id);

        return new BannerResource($banner);
    }

    #[Endpoint(title: 'تحديث لافتة إعلانية', description: 'يحدّث بيانات اللافتة وصورتها وفترة ظهورها وترتيبها.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للافتة.')]
    #[Response(200, description: 'بيانات اللافتة بعد التحديث.')]
    public function update(UpdateBannerRequest $request, $id)
    {
        $banner = $this->service->show($id);
        $banner = $this->service->update($banner, $request->validated());

        return new BannerResource($banner);
    }

    #[Endpoint(title: 'حذف لافتة إعلانية', description: 'يحذف اللافتة الإعلانية المحددة من النظام.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للافتة.')]
    #[Response(200, description: 'تم حذف اللافتة الإعلانية بنجاح.')]
    public function destroy($id)
    {
        $banner = $this->service->show($id);
        $this->service->delete($banner);

        return response()->json(['message' => 'Banner deleted successfully']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\CharitySystemRequest;
use App\Http\Requests\UpdateCharitySystemRequest;
use App\Http\Resources\CharitySystemResource;
use App\Services\CharitySystemService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group(name: 'المبادرات الخيرية', description: 'المبادرات والجهات الخيرية الظاهرة للمستخدمين وإدارتها من لوحة التحكم.', weight: 17)]
class CharitySystemController extends Controller
{
    protected $service;

    public function __construct(CharitySystemService $service)
    {
        $this->service = $service;
    }

    #[Endpoint(title: 'عرض المبادرات الخيرية النشطة', description: 'يعرض المبادرات الخيرية النشطة والمتاحة حاليًا للمستخدمين.')]
    #[Response(200, description: 'قائمة المبادرات الخيرية النشطة.')]
    public function index()
    {
        $banners = $this->service->allActive();

        return CharitySystemResource::collection($banners);
    }

    #[Endpoint(title: 'عرض جميع المبادرات للإدارة', description: 'يعرض جميع المبادرات الخيرية داخل لوحة الإدارة بغض النظر عن حالتها.')]
    #[Response(200, description: 'قائمة المبادرات الخيرية الكاملة.')]
    public function indexforadmin()
    {
        $banners = $this->service->list();

        return CharitySystemResource::collection($banners);
    }

    #[Endpoint(title: 'إنشاء مبادرة خيرية', description: 'ينشئ مبادرة خيرية جديدة بالبيانات المحددة.')]
    #[Response(200, description: 'بيانات المبادرة بعد إنشائها.')]
    public function store(CharitySystemRequest $request)
    {
        $banner = $this->service->create($request->validated());

        return new CharitySystemResource($banner);
    }

    #[Endpoint(title: 'عرض مبادرة للإدارة', description: 'يعرض تفاصيل مبادرة خيرية محددة داخل لوحة الإدارة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمبادرة.')]
    #[Response(200, description: 'بيانات المبادرة المطلوبة.')]
    public function show($id)
    {
        $banner = $this->service->show($id);

        return new CharitySystemResource($banner);
    }

    #[Endpoint(title: 'تحديث مبادرة خيرية', description: 'يحدّث بيانات المبادرة الخيرية المحددة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمبادرة.')]
    #[Response(200, description: 'بيانات المبادرة بعد التحديث.')]
    public function update(UpdateCharitySystemRequest $request, $id)
    {
        $banner = $this->service->show($id);
        $banner = $this->service->update($banner, $request->validated());

        return new CharitySystemResource($banner);
    }

    #[Endpoint(title: 'حذف مبادرة خيرية', description: 'يحذف المبادرة الخيرية المحددة من النظام.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمبادرة.')]
    #[Response(200, description: 'تم حذف المبادرة الخيرية بنجاح.')]
    public function destroy($id)
    {
        $banner = $this->service->show($id);
        $this->service->delete($banner);

        return response()->json(['message' => 'CharitySystem deleted successfully']);
    }
}

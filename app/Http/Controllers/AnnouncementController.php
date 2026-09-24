<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnouncementRequest;
use App\Http\Requests\AnnouncementUpdateRequest;
use App\Http\Resources\AnnouncementResource;
use App\Services\AnnouncementService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group(name: 'التنويهات', description: 'التنويهات العامة المنشورة للمستخدمين وإدارتها من لوحة التحكم.', weight: 17)]
class AnnouncementController extends Controller
{
    protected $service;

    public function __construct(AnnouncementService $service)
    {
        $this->service = $service;
    }

    #[Endpoint(title: 'عرض التنويهات النشطة', description: 'يعرض التنويهات النشطة والمتاحة حاليًا للمستخدمين.')]
    #[Response(200, description: 'قائمة التنويهات النشطة.')]
    public function index()
    {
        $announcements = $this->service->allActive();

        return AnnouncementResource::collection($announcements);
    }

    #[Endpoint(title: 'عرض جميع التنويهات للإدارة', description: 'يعرض جميع التنويهات داخل لوحة الإدارة بغض النظر عن حالتها.')]
    #[Response(200, description: 'قائمة التنويهات الكاملة.')]
    public function indexforadmin()
    {
        $announcements = $this->service->list();

        return AnnouncementResource::collection($announcements);
    }

    #[Endpoint(title: 'إنشاء تنويه', description: 'ينشئ تنويهًا جديدًا بالبيانات والفترة الزمنية المحددة.')]
    #[Response(200, description: 'بيانات التنويه بعد إنشائه.')]
    public function store(AnnouncementRequest $request)
    {
        $announcement = $this->service->create($request->validated());

        return new AnnouncementResource($announcement);
    }

    #[Endpoint(title: 'عرض تنويه للإدارة', description: 'يعرض تفاصيل تنويه محدد داخل لوحة الإدارة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للتنويه.')]
    #[Response(200, description: 'بيانات التنويه المطلوبة.')]
    public function show($id)
    {
        $announcement = $this->service->show($id);

        return new AnnouncementResource($announcement);
    }

    #[Endpoint(title: 'تحديث تنويه', description: 'يحدّث بيانات التنويه المحدد ومحتواه وفترة ظهوره.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للتنويه.')]
    #[Response(200, description: 'بيانات التنويه بعد التحديث.')]
    public function update(AnnouncementUpdateRequest $request, $id)
    {
        $announcement = $this->service->show($id);
        $announcement = $this->service->update($announcement, $request);

        return new AnnouncementResource($announcement);
    }

    #[Endpoint(title: 'حذف تنويه', description: 'يحذف التنويه المحدد من النظام.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للتنويه.')]
    #[Response(200, description: 'تم حذف التنويه بنجاح.')]
    public function destroy($id)
    {
        $announcement = $this->service->show($id);
        $this->service->delete($announcement);

        return response()->json(['message' => 'Announcement deleted successfully']);
    }
}

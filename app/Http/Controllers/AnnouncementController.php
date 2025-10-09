<?php

namespace App\Http\Controllers;


use App\Services\AnnouncementService;
use App\Http\Requests\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;

class AnnouncementController extends Controller
{
    protected $service;

    public function __construct(AnnouncementService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $announcements = $this->service->allActive();
        return AnnouncementResource::collection($announcements);
    }
    public function indexforadmin()
    {
        $announcements = $this->service->list();
        return AnnouncementResource::collection($announcements);
    }

    public function store(AnnouncementRequest $request)
    {
        $announcement = $this->service->create($request->validated());
        return new AnnouncementResource($announcement);
    }

    public function show($id)
    {
        $announcement = $this->service->show($id);
        return new AnnouncementResource($announcement);
    }

    public function update(AnnouncementRequest $request, $id)
    {
        $announcement = $this->service->show($id);
        $announcement = $this->service->update($announcement, $request->validated());
        return new AnnouncementResource($announcement);
    }

    public function destroy($id)
    {
        $announcement = $this->service->show($id);
        $this->service->delete($announcement);
        return response()->json(['message' => 'Announcement deleted successfully']);
    }
}

<?php

namespace App\Http\Controllers;


use App\Services\BannerService;
use App\Http\Requests\BannerRequest;
use App\Http\Requests\UpdateBannerRequest;
use App\Http\Resources\BannerResource;

class BannerController extends Controller
{
    protected $service;

    public function __construct(BannerService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $banners = $this->service->allActive();
        return BannerResource::collection($banners);
    }
    public function indexforadmin()
    {
        $banners = $this->service->list();
        return BannerResource::collection($banners);
    }

    public function store(BannerRequest $request)
    {
        $banner = $this->service->create($request->validated());
        return new BannerResource($banner);
    }

    public function show($id)
    {
        $banner = $this->service->show($id);
        return new BannerResource($banner);
    }

    public function update(UpdateBannerRequest $request, $id)
    {
        $banner = $this->service->show($id);
        $banner = $this->service->update($banner, $request->validated());
        return new BannerResource($banner);
    }

    public function destroy($id)
    {
        $banner = $this->service->show($id);
        $this->service->delete($banner);
        return response()->json(['message' => 'Banner deleted successfully']);
    }
}

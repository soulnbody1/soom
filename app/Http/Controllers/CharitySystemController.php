<?php

namespace App\Http\Controllers;


use App\Services\CharitySystemService;
use App\Http\Requests\CharitySystemRequest;
use App\Http\Requests\UpdateCharitySystemRequest;
use App\Http\Resources\CharitySystemResource;

class CharitySystemController extends Controller
{
    protected $service;

    public function __construct(CharitySystemService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $banners = $this->service->allActive();
        return CharitySystemResource::collection($banners);
    }
    public function indexforadmin()
    {
        $banners = $this->service->list();
        return CharitySystemResource::collection($banners);
    }

    public function store(CharitySystemRequest $request)
    {
        $banner = $this->service->create($request->validated());
        return new CharitySystemResource($banner);
    }

    public function show($id)
    {
        $banner = $this->service->show($id);
        return new CharitySystemResource($banner);
    }

    public function update(UpdateCharitySystemRequest $request, $id)
    {
        $banner = $this->service->show($id);
        $banner = $this->service->update($banner, $request->validated());
        return new CharitySystemResource($banner);
    }

    public function destroy($id)
    {
        $banner = $this->service->show($id);
        $this->service->delete($banner);
        return response()->json(['message' => 'CharitySystem deleted successfully']);
    }
}

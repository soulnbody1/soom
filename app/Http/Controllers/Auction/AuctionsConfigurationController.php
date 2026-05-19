<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuctionsConfigurationRequest;
use App\Http\Resources\AuctionsConfigurationResource;
use App\Models\AuctionsConfiguration;
use App\Traits\ApiResponseTrait;

class AuctionsConfigurationController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {
        $configs = AuctionsConfiguration::with('category')->latest()->get();
        return AuctionsConfigurationResource::collection($configs);
    }

    public function store(AuctionsConfigurationRequest $request)
    {
        $config = AuctionsConfiguration::create($request->validated());
        return new AuctionsConfigurationResource($config->load('category'));
    }

    public function show($id)
    {
        $config = AuctionsConfiguration::with('category')->find($id);
        if (!$config) {
            return $this->sendError('الإعداد غير موجود.', 404);
        }
        return new AuctionsConfigurationResource($config);
    }

    public function update(AuctionsConfigurationRequest $request, $id)
    {
        $config = AuctionsConfiguration::find($id);
        if (!$config) {
            return $this->sendError('الإعداد غير موجود.', 404);
        }
        $config->update($request->validated());
        return new AuctionsConfigurationResource($config->fresh()->load('category'));
    }

    public function destroy($id)
    {
        $config = AuctionsConfiguration::find($id);
        if (!$config) {
            return $this->sendError('الإعداد غير موجود.', 404);
        }
        $config->delete();
        return $this->sendResponse([], 'تم حذف الإعداد بنجاح.');
    }
}
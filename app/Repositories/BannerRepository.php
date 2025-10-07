<?php

namespace App\Repositories;

use App\Models\Banner;

class BannerRepository
{

    public function BannerActive()
    {
        return Banner::where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now());
            })
            ->orderBy('display_order')
            ->get();
    }

    public function all()
    {
        return Banner::orderBy('display_order')->get();
    }

    public function find($id)
    {
        return Banner::findOrFail($id);
    }

    public function create(array $data)
    {
        return Banner::create($data);
    }

    public function update(Banner $banner, array $data)
    {
        $banner->update($data);
        return $banner;
    }

    public function delete(Banner $banner)
    {
        return $banner->delete();
    }
}

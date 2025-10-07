<?php

namespace App\Services;

use App\Models\Banner;
use App\Repositories\BannerRepository;
use Illuminate\Support\Facades\Storage;


class BannerService
{
    protected $repo;

    public function __construct(BannerRepository $repo)
    {
        $this->repo = $repo;
    }

    public function allActive()
    {
        return $this->repo->BannerActive();
    }


    public function list()
    {
        return $this->repo->all();
    }

    public function create(array $data)
    {
        if (request()->hasFile('image')) {
            $data['image'] = request()->file('image')->store('banners', 'spaces');
        }

        return $this->repo->create($data);
    }

    public function update(Banner $banner, array $data)
    {
        if (request()->hasFile('image')) {
            if ($banner->image) {
                Storage::disk('spaces')->delete($banner->image);
            }

            $data['image'] = request()->file('image')->store('banners', 'spaces');
        }
        return $this->repo->update($banner, $data);
    }

    public function delete(Banner $banner)
    {
        if ($banner->image) {
            Storage::disk('spaces')->delete($banner->image);
        }
        return $this->repo->delete($banner);
    }

    public function show($id)
    {
        return $this->repo->find($id);
    }
}

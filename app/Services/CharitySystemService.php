<?php

namespace App\Services;

use App\Models\CharitySystem;
use App\Repositories\CharitySystemRepository;
use Illuminate\Support\Facades\Storage;


class CharitySystemService
{
    protected $repo;

    public function __construct(CharitySystemRepository $repo)
    {
        $this->repo = $repo;
    }

    public function allActive()
    {
        return $this->repo->CharitySystemActive();
    }


    public function list()
    {
        return $this->repo->all();
    }

    public function create(array $data)
    {
        if (request()->hasFile('image')) {
            $data['image'] = request()->file('image')->store('charity_system', 'spaces');
        }

        return $this->repo->create($data);
    }

    public function update(CharitySystem $banner, array $data)
    {
        if (request()->hasFile('image')) {
            if ($banner->image) {
                $originalPath = ltrim($banner->getRawOriginal('image'), '/');
                Storage::disk('spaces')->delete($originalPath);
            }

            $data['image'] = request()->file('image')->store('charity_system', 'spaces');
        }
        return $this->repo->update($banner, $data);
    }

    public function delete(CharitySystem $banner)
    {
        if ($banner->image) {
            $originalPath = ltrim($banner->getRawOriginal('image'), '/');
            Storage::disk('spaces')->delete($originalPath);
        }
        return $this->repo->delete($banner);
    }

    public function show($id)
    {
        return $this->repo->find($id);
    }
}

<?php

namespace App\Repositories;

use App\Models\CharitySystem;

class CharitySystemRepository
{

    public function CharitySystemActive()
    {
        return CharitySystem::where('is_active', 1)
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
        return CharitySystem::orderBy('display_order')->get();
    }

    public function find($id)
    {
        return CharitySystem::findOrFail($id);
    }

    public function create(array $data)
    {
        return CharitySystem::create($data);
    }

    public function update(CharitySystem $charity_system, array $data)
    {
        $charity_system->update($data);
        return $charity_system;
    }

    public function delete(CharitySystem $charity_system)
    {
        return $charity_system->delete();
    }
}

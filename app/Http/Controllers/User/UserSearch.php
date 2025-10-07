<?php

namespace App\Http\Controllers\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class UserSearch
{
    public function apply(Request $request): Builder
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->whereRaw("MATCH(name, phone) AGAINST(? IN BOOLEAN MODE)", [$search])
                    ->orWhere('name', 'LIKE', '%' . $search . '%')
                    ->orWhere('phone', 'LIKE', '%' . $search . '%');
            });
        }

        return $query;
    }
}



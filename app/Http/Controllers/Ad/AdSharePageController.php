<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Contracts\View\View;

class AdSharePageController extends Controller
{
    public function __invoke(int $id): View
    {
        return view('share.show', ['ad' => Ad::with('images')->findOrFail($id)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

trait ResolvesAdminPerPage
{
    public function perPage(int $default = 15): int
    {
        return min(100, max(1, (int) $this->input('per_page', $default)));
    }
}

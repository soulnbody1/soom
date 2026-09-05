<?php

declare(strict_types=1);

namespace App\DTO\Ad;

use Illuminate\Http\Request;

final readonly class AdSearchDTO
{
    public function __construct(
        public ?string $category = null,
        public ?string $keyword = null,
        public ?string $status = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            category: $request->filled('category') ? trim((string) $request->input('category')) : null,
            keyword: $request->filled('title') ? trim((string) $request->input('title')) : null,
            status: $request->filled('status') ? (string) $request->input('status') : null,
        );
    }
}

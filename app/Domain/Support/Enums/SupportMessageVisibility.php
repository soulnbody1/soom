<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

enum SupportMessageVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}

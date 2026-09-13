<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

enum SupportAuthorType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case System = 'system';
}

<?php

declare(strict_types=1);

namespace App\Domain\Ad\Enums;

enum AdInteractionAction: string
{
    case Click = 'click';
    case Save = 'save';
}

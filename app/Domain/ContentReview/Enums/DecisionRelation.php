<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum DecisionRelation: string
{
    case None = 'none';
    case Confirmed = 'confirmed';
    case Overridden = 'overridden';
    case Unavailable = 'unavailable';
}

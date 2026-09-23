<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ReviewableSubjectType: string
{
    case Auction = 'auction';
    case Ad = 'ad';
}

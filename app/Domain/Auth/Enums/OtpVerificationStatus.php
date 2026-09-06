<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

enum OtpVerificationStatus
{
    case Valid;
    case Invalid;
    case Expired;
    case TooManyAttempts;
}

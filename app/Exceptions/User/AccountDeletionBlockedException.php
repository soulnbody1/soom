<?php

declare(strict_types=1);

namespace App\Exceptions\User;

use RuntimeException;

final class AccountDeletionBlockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('لا يمكن حذف الحساب نهائياً لارتباطه بنشاط مزادات أو معاملات مالية.');
    }
}

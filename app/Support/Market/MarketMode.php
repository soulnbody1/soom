<?php

namespace App\Support\Market;

enum MarketMode: string
{
    case MarketRequest = 'market_request';
    case AccountGlobal = 'account_global';
    case AdminMarket = 'admin_market';
    case AdminAll = 'admin_all';
    case SystemMarket = 'system_market';
    case SystemGlobal = 'system_global';

    public function requiresMarket(): bool
    {
        return match ($this) {
            self::MarketRequest, self::AdminMarket, self::SystemMarket => true,
            self::AccountGlobal, self::AdminAll, self::SystemGlobal => false,
        };
    }

    public function isGlobal(): bool
    {
        return ! $this->requiresMarket();
    }
}

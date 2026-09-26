<?php

declare(strict_types=1);

namespace Database\Seeders\Market;

use App\Services\Market\MarketBootstrapper;
use App\Services\Market\Profiles\JordanMarketProfile;
use Illuminate\Database\Seeder;

final class JordanMarketSeeder extends Seeder
{
    public function run(): void
    {
        app(MarketBootstrapper::class)->run(app(JordanMarketProfile::class), true);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders\Market;

use App\Services\Market\MarketBootstrapper;
use App\Services\Market\Profiles\EgyptMarketProfile;
use Illuminate\Database\Seeder;

final class EgyptMarketSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(EgyptLocationSeeder::class);
        app(MarketBootstrapper::class)->run(app(EgyptMarketProfile::class), true);
    }
}

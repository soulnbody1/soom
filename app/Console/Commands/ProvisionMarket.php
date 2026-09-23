<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Market\MarketProfile;
use App\Services\Market\MarketProvisioner;
use App\Services\Market\Profiles\EgyptMarketProfile;
use Database\Seeders\Market\EgyptLocationSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Throwable;

final class ProvisionMarket extends Command
{
    protected $signature = 'market:provision {code : The two-letter market code} {--no-activate : Load the reference data without activating the market}';

    protected $description = 'Load a market reference data set and activate the market once every prerequisite is present.';

    /** @var array<string, array{profile: class-string<MarketProfile>, seeders: list<class-string<Seeder>>}> */
    private const PROFILES = [
        'EG' => ['profile' => EgyptMarketProfile::class, 'seeders' => [EgyptLocationSeeder::class]],
    ];

    public function handle(MarketProvisioner $provisioner): int
    {
        $code = strtoupper(trim((string) $this->argument('code')));

        if (! isset(self::PROFILES[$code])) {
            $this->components->error("No provisioning profile is registered for market [{$code}].");

            return self::FAILURE;
        }

        $definition = self::PROFILES[$code];

        try {
            foreach ($definition['seeders'] as $seeder) {
                $this->components->task('seeding '.class_basename($seeder), function () use ($seeder): void {
                    $this->callSilent('db:seed', ['--class' => $seeder, '--force' => true]);
                });
            }

            $profile = app($definition['profile']);
            $counts = $provisioner->provision($profile);
        } catch (Throwable $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        foreach ($counts as $label => $count) {
            $this->components->twoColumnDetail($label, (string) $count);
        }

        $missing = $provisioner->readiness($code, $profile);

        if ($missing !== []) {
            foreach ($missing as $item) {
                $this->components->twoColumnDetail('missing', $item);
            }
            $this->components->error("Market {$code} is not ready and was not activated.");

            return self::FAILURE;
        }

        if ($this->option('no-activate')) {
            $this->components->info("Market {$code} is ready. Activation was skipped.");

            return self::SUCCESS;
        }

        $market = $provisioner->activate($code, $profile);

        $this->components->info("Market {$market->code} is active at https://{$market->web_host} via https://{$market->api_host}.");

        return self::SUCCESS;
    }
}

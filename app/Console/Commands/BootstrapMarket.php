<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Market\MarketBootstrapProfile;
use App\Services\Market\MarketBootstrapper;
use App\Services\Market\MarketProvisioner;
use App\Services\Market\Profiles\EgyptMarketProfile;
use App\Services\Market\Profiles\JordanMarketProfile;
use Database\Seeders\Market\EgyptLocationSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Throwable;

final class BootstrapMarket extends Command
{
    protected $signature = 'market:bootstrap
        {code : JO or EG}
        {--dry-run : Inspect the required changes without writing to the database}
        {--apply : Apply the profile and activate the market when it is ready}
        {--force : Allow --apply in production}';

    protected $description = 'Plan or apply the production-safe auction baseline for Jordan or Egypt.';

    /** @var array<string, array{profile: class-string<MarketBootstrapProfile>, seeders: list<class-string<Seeder>>}> */
    private const PROFILES = [
        'JO' => ['profile' => JordanMarketProfile::class, 'seeders' => []],
        'EG' => ['profile' => EgyptMarketProfile::class, 'seeders' => [EgyptLocationSeeder::class]],
    ];

    public function handle(MarketBootstrapper $bootstrapper, MarketProvisioner $provisioner): int
    {
        $code = strtoupper(trim((string) $this->argument('code')));
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->components->error('Choose exactly one mode: --dry-run or --apply.');

            return self::INVALID;
        }

        if (! isset(self::PROFILES[$code])) {
            $this->components->error("No bootstrap profile is registered for market [{$code}].");

            return self::INVALID;
        }

        if ($apply && app()->environment('production') && ! $this->option('force')) {
            $this->components->error('Refusing to change production without --force. Run --dry-run first.');

            return self::FAILURE;
        }

        $definition = self::PROFILES[$code];
        /** @var MarketBootstrapProfile $profile */
        $profile = app($definition['profile']);

        try {
            if ($apply) {
                foreach ($definition['seeders'] as $seeder) {
                    $this->components->task('seeding '.class_basename($seeder), function () use ($seeder): void {
                        $this->callSilent('db:seed', ['--class' => $seeder, '--force' => true]);
                    });
                }
            }

            $result = $bootstrapper->run($profile, $apply);
        } catch (Throwable $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $rows = collect($result['operations'])
            ->map(fn (array $operation): array => [
                $operation['resource'],
                $operation['action'],
                $operation['details'],
            ])
            ->all();

        if ($code === 'EG') {
            array_unshift($rows, [
                'locations',
                $apply ? 'synchronized' : 'would synchronize',
                '27 governorates and their cities.',
            ]);
        }

        $this->table(['Resource', 'Action', 'Details'], $rows);

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if ($dryRun) {
            $this->components->info("Dry run complete for {$code}; no database changes were made.");

            return self::SUCCESS;
        }

        $missing = $provisioner->readiness($code, $profile);

        if ($missing !== []) {
            foreach ($missing as $item) {
                $this->components->twoColumnDetail('missing', $item);
            }

            $this->components->error("Market {$code} was bootstrapped but cannot be activated yet.");

            return self::FAILURE;
        }

        $market = $provisioner->activate($code, $profile);
        $this->components->info("Market {$market->code} is bootstrapped and active.");

        return self::SUCCESS;
    }
}

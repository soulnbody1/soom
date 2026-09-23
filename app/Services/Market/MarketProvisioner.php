<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Contracts\Market\MarketProfile;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Market;
use App\Models\SupportContact;
use App\Services\Catalog\CatalogCacheVersion;
use App\Support\Market\MarketContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class MarketProvisioner
{
    public function __construct(
        private MarketContext $context,
        private MarketCommandRunner $runner,
        private CatalogCacheVersion $catalogCache,
    ) {}

    /** @return array<string, int> */
    public function provision(MarketProfile $profile): array
    {
        $market = $this->market($profile->marketCode());

        return [
            'categories' => $this->copyCategories($profile, $market),
            'auction_configuration_versions' => $this->configurationVersion($profile),
            'auction_terms_versions' => $this->termsVersion($profile),
            'payment_methods' => $this->paymentMethods($profile),
            'support_contacts' => $this->copySupportContact($profile, $market),
        ];
    }

    /** @return list<string> */
    public function readiness(string $code, ?MarketProfile $profile = null): array
    {
        $market = $this->market($code);

        return $this->context->runInMarket($market, function () use ($market, $profile): array {
            $missing = [];

            if (DB::table('market_category')->where('market_id', $market->getKey())->where('is_visible', true)->doesntExist()) {
                $missing[] = $this->categoryHint($profile);
            }

            if (DB::table('states')->where('country_id', $market->country_id)->doesntExist()) {
                $missing[] = 'the market country has no states';
            }

            $hasCities = DB::table('cities')
                ->join('states', 'states.id', '=', 'cities.state_id')
                ->where('states.country_id', $market->country_id)
                ->exists();

            if (! $hasCities) {
                $missing[] = 'the market country has no cities';
            }

            if (AuctionConfigurationVersion::query()->where('is_active', true)->whereNotNull('published_at')->doesntExist()) {
                $missing[] = 'no active auction configuration version';
            }

            if (AuctionTermsVersion::query()->where('is_active', true)->whereNotNull('published_at')->doesntExist()) {
                $missing[] = 'no active auction terms version';
            }

            if (PaymentMethod::query()->where('is_active', true)->doesntExist()) {
                $missing[] = 'no active payment method';
            }

            if ($market->web_host === null || $market->api_host === null) {
                $missing[] = 'the market has no web host or api host';
            }

            return $missing;
        });
    }

    public function activate(string $code, ?MarketProfile $profile = null): Market
    {
        $missing = $this->readiness($code, $profile);

        if ($missing !== []) {
            throw new RuntimeException('Market '.strtoupper($code).' is not ready to activate: '.implode('; ', $missing).'.');
        }

        $market = $this->market($code);
        $market->forceFill(['is_active' => true])->save();

        return $market->refresh();
    }

    private function categoryHint(?MarketProfile $profile): string
    {
        if ($profile === null) {
            return 'no visible category is mapped to this market';
        }

        $source = $this->market($profile->categorySourceMarketCode());
        $sourceHasNone = DB::table('market_category')
            ->where('market_id', $source->getKey())
            ->where('is_visible', true)
            ->doesntExist();

        return $sourceHasNone
            ? "no visible category is mapped to this market, and the source market {$source->code} has none to mirror; create the catalogue first"
            : 'no visible category is mapped to this market';
    }

    private function market(string $code): Market
    {
        $normalized = strtoupper(trim($code));

        return $this->context->runGlobally(function () use ($normalized): Market {
            $market = Market::query()->where('code', $normalized)->first();

            return $market ?? throw new RuntimeException('Unknown market ['.$normalized.'].');
        });
    }

    private function copyCategories(MarketProfile $profile, Market $market): int
    {
        $source = $this->market($profile->categorySourceMarketCode());
        $now = Carbon::now();
        $copied = 0;

        DB::table('market_category')->where('market_id', $source->getKey())->orderBy('category_id')
            ->chunk(500, function ($rows) use ($market, $now, &$copied): void {
                foreach ($rows as $row) {
                    DB::table('market_category')->updateOrInsert(
                        ['market_id' => $market->getKey(), 'category_id' => $row->category_id],
                        ['is_visible' => $row->is_visible, 'display_order' => $row->display_order, 'updated_at' => $now, 'created_at' => $now]
                    );
                    $copied++;
                }
            });

        $this->catalogCache->bump();

        return $copied;
    }

    private function configurationVersion(MarketProfile $profile): int
    {
        return (int) $this->runner->in($profile->marketCode(), function () use ($profile): int {
            if (AuctionConfigurationVersion::query()->where('is_active', true)->exists()) {
                return 0;
            }

            AuctionConfigurationVersion::query()->create([
                'version_number' => (int) AuctionConfigurationVersion::query()->max('version_number') + 1,
                'configuration' => $profile->auctionConfiguration(),
                'is_active' => true,
                'published_at' => Carbon::now(),
            ]);

            return 1;
        });
    }

    private function termsVersion(MarketProfile $profile): int
    {
        return (int) $this->runner->in($profile->marketCode(), function () use ($profile): int {
            if (AuctionTermsVersion::query()->where('is_active', true)->exists()) {
                return 0;
            }

            $terms = $profile->auctionTerms();

            AuctionTermsVersion::query()->create([
                'version_number' => (int) AuctionTermsVersion::query()->max('version_number') + 1,
                'title' => $terms['title'],
                'body' => $terms['body'],
                'is_active' => true,
                'published_at' => Carbon::now(),
            ]);

            return 1;
        });
    }

    private function paymentMethods(MarketProfile $profile): int
    {
        return (int) $this->runner->in($profile->marketCode(), function () use ($profile): int {
            $created = 0;

            foreach ($profile->paymentMethods() as $definition) {
                if (PaymentMethod::query()->where('code', $definition['code'])->exists()) {
                    continue;
                }

                PaymentMethod::query()->create($definition);
                $created++;
            }

            return $created;
        });
    }

    private function copySupportContact(MarketProfile $profile, Market $market): int
    {
        $source = $this->market($profile->supportContactSourceMarketCode());

        $template = $this->context->runInMarket(
            $source,
            fn (): ?SupportContact => SupportContact::query()->first()
        );

        if ($template === null) {
            return 0;
        }

        return (int) $this->runner->in($profile->marketCode(), function () use ($market, $template): int {
            if (SupportContact::query()->exists()) {
                return 0;
            }

            SupportContact::query()->create([
                'market_id' => $market->getKey(),
                'whatsapp' => $template->whatsapp,
                'phone' => $template->phone,
                'email' => $template->email,
                'availability' => $template->availability,
            ]);

            return 1;
        });
    }
}

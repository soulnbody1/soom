<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Contracts\Market\MarketBootstrapProfile;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Models\ContentReview\ContentReviewSetting;
use App\Models\Market;
use App\Models\SupportContact;
use App\Services\Catalog\CatalogCacheVersion;
use App\Services\ContentReview\Actions\PublishContentReviewPolicyAction;
use App\Services\ContentReview\Actions\PublishContentReviewSettingsAction;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class MarketBootstrapper
{
    public function __construct(
        private MarketCommandRunner $runner,
        private MarketContextResolver $markets,
        private CatalogCacheVersion $catalogCache,
        private PublishContentReviewPolicyAction $publishPolicy,
        private PublishContentReviewSettingsAction $publishSettings,
        private ContentReviewProviderFactory $contentReviewProviders,
    ) {}

    /**
     * @return array{
     *     market: string,
     *     applied: bool,
     *     operations: list<array{resource: string, action: string, details: string}>,
     *     warnings: list<string>
     * }
     */
    public function run(MarketBootstrapProfile $profile, bool $apply): array
    {
        $operations = $this->runner->in(
            $profile->marketCode(),
            fn (Market $market): array => $apply
                ? DB::transaction(fn (): array => $this->synchronize($profile, $market, true))
                : $this->synchronize($profile, $market, false)
        );

        return [
            'market' => strtoupper($profile->marketCode()),
            'applied' => $apply,
            'operations' => $operations,
            'warnings' => $this->warnings($profile),
        ];
    }

    /** @return list<array{resource: string, action: string, details: string}> */
    private function synchronize(MarketBootstrapProfile $profile, Market $market, bool $apply): array
    {
        return [
            $this->categories($profile, $market, $apply),
            $this->configuration($profile, $apply),
            $this->terms($profile, $apply),
            $this->paymentMethods($profile, $apply),
            $this->supportContact($profile, $apply),
            $this->contentReviewPolicy($profile, $apply),
            $this->contentReviewSettings($profile, $apply),
        ];
    }

    /** @return array{resource: string, action: string, details: string} */
    private function categories(MarketBootstrapProfile $profile, Market $market, bool $apply): array
    {
        $source = $this->markets->market($profile->categorySourceMarketCode());

        if ($source->is($market)) {
            return $this->operation('categories', 'unchanged', 'The market is the catalogue source.');
        }

        $sourceRows = DB::table('market_category')
            ->where('market_id', $source->getKey())
            ->orderBy('category_id')
            ->get(['category_id', 'is_visible', 'display_order']);

        $changed = 0;

        foreach ($sourceRows as $row) {
            $current = DB::table('market_category')
                ->where('market_id', $market->getKey())
                ->where('category_id', $row->category_id)
                ->first(['is_visible', 'display_order']);

            if ($current !== null
                && (bool) $current->is_visible === (bool) $row->is_visible
                && (int) $current->display_order === (int) $row->display_order) {
                continue;
            }

            $changed++;

            if ($apply) {
                DB::table('market_category')->updateOrInsert(
                    ['market_id' => $market->getKey(), 'category_id' => $row->category_id],
                    [
                        'is_visible' => $row->is_visible,
                        'display_order' => $row->display_order,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]
                );
            }
        }

        if ($apply && $changed > 0) {
            $this->catalogCache->bump();
        }

        return $this->operation(
            'categories',
            $changed === 0 ? 'unchanged' : ($apply ? 'synchronized' : 'would synchronize'),
            "{$changed} of {$sourceRows->count()} mappings differ from {$source->code}."
        );
    }

    /** @return array{resource: string, action: string, details: string} */
    private function configuration(MarketBootstrapProfile $profile, bool $apply): array
    {
        $desired = $profile->auctionConfiguration();
        $current = AuctionConfigurationVersion::query()
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->orderByDesc('version_number')
            ->first();

        if ($current !== null && $this->same($current->configuration, $desired)) {
            return $this->operation('auction configuration', 'unchanged', "Active version {$current->version_number} matches the profile.");
        }

        $version = ((int) AuctionConfigurationVersion::query()->max('version_number')) + 1;

        if ($apply) {
            AuctionConfigurationVersion::query()->create([
                'version_number' => $version,
                'configuration' => $desired,
                'is_active' => true,
                'published_at' => Carbon::now(),
            ]);
        }

        return $this->operation(
            'auction configuration',
            $apply ? 'published' : 'would publish',
            "Append-only version {$version}."
        );
    }

    /** @return array{resource: string, action: string, details: string} */
    private function terms(MarketBootstrapProfile $profile, bool $apply): array
    {
        $desired = $profile->auctionTerms();
        $current = AuctionTermsVersion::query()
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->orderByDesc('version_number')
            ->first();

        if ($current !== null
            && $current->title === $desired['title']
            && $current->body === $desired['body']) {
            return $this->operation('auction terms', 'unchanged', "Active version {$current->version_number} matches the profile.");
        }

        $version = ((int) AuctionTermsVersion::query()->max('version_number')) + 1;

        if ($apply) {
            AuctionTermsVersion::query()->update(['is_active' => false]);
            AuctionTermsVersion::query()->create([
                'version_number' => $version,
                'title' => $desired['title'],
                'body' => $desired['body'],
                'is_active' => true,
                'published_at' => Carbon::now(),
            ]);
        }

        return $this->operation('auction terms', $apply ? 'published' : 'would publish', "Version {$version}.");
    }

    /** @return array{resource: string, action: string, details: string} */
    private function paymentMethods(MarketBootstrapProfile $profile, bool $apply): array
    {
        $definitions = collect($profile->paymentMethods())->keyBy('code');
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($definitions as $code => $definition) {
            $method = PaymentMethod::query()->where('code', $code)->first();

            if ($method === null) {
                $created++;
                if ($apply) {
                    PaymentMethod::query()->create($definition);
                }

                continue;
            }

            if ($this->modelMatches($method, $definition)) {
                $unchanged++;

                continue;
            }

            $updated++;
            if ($apply) {
                $method->fill($definition)->save();
            }
        }

        $extras = PaymentMethod::query()
            ->where('is_active', true)
            ->whereNotIn('code', $definitions->keys()->all())
            ->get();

        if ($apply) {
            foreach ($extras as $extra) {
                $extra->forceFill(['is_active' => false])->save();
            }
        }

        $action = ($created + $updated + $extras->count()) === 0
            ? 'unchanged'
            : ($apply ? 'synchronized' : 'would synchronize');

        return $this->operation(
            'payment methods',
            $action,
            "create={$created}, update={$updated}, unchanged={$unchanged}, deactivate={$extras->count()}."
        );
    }

    /** @return array{resource: string, action: string, details: string} */
    private function supportContact(MarketBootstrapProfile $profile, bool $apply): array
    {
        $desired = $profile->supportContact();
        $contact = SupportContact::query()->orderBy('id')->first();

        if ($contact !== null && $this->modelMatches($contact, $desired)) {
            return $this->operation('support contact', 'unchanged', 'The configured contact matches the profile.');
        }

        if ($apply) {
            if ($contact === null) {
                SupportContact::query()->create($desired);
            } else {
                $contact->fill($desired)->save();
            }
        }

        return $this->operation(
            'support contact',
            $apply ? ($contact === null ? 'created' : 'updated') : ($contact === null ? 'would create' : 'would update'),
            'Temporary test contact; replace before launch.'
        );
    }

    /** @return array{resource: string, action: string, details: string} */
    private function contentReviewPolicy(MarketBootstrapProfile $profile, bool $apply): array
    {
        $definition = $profile->contentReviewPolicy();
        $subject = ReviewableSubjectType::Auction;
        $current = ContentReviewPolicy::query()
            ->where('subject_type', $subject->value)
            ->where('is_active', true)
            ->orderByDesc('version_number')
            ->first();

        $matches = $current !== null
            && $current->name === $definition['name']
            && $current->prompt_version === $definition['prompt_version']
            && (int) $current->result_schema_version === $definition['result_schema_version']
            && $this->same($current->policy, $definition['policy']);

        if ($matches) {
            return $this->operation('content review policy', 'unchanged', "Active version {$current->version_number} matches the profile.");
        }

        $version = ((int) ContentReviewPolicy::query()->where('subject_type', $subject->value)->max('version_number')) + 1;

        if ($apply) {
            $this->publishPolicy->execute(
                $subject,
                $definition['name'],
                $definition['policy'],
                $definition['prompt_version'],
                $definition['result_schema_version'],
                null,
            );
        }

        return $this->operation('content review policy', $apply ? 'published' : 'would publish', "Version {$version}.");
    }

    /** @return array{resource: string, action: string, details: string} */
    private function contentReviewSettings(MarketBootstrapProfile $profile, bool $apply): array
    {
        $scope = ReviewableSubjectType::Auction->value;
        $desired = $profile->contentReviewSettings();
        $current = ContentReviewSetting::query()
            ->where('scope', $scope)
            ->where('is_active', true)
            ->orderByDesc('version_number')
            ->first();

        if ($current !== null && $this->same($current->settings, $desired)) {
            return $this->operation('content review settings', 'unchanged', "Active version {$current->version_number} matches the profile.");
        }

        $version = ((int) ContentReviewSetting::query()->where('scope', $scope)->max('version_number')) + 1;

        if ($apply) {
            $this->publishSettings->execute($scope, $desired, null);
        }

        return $this->operation('content review settings', $apply ? 'published' : 'would publish', "Version {$version}, Gemini shadow mode.");
    }

    /** @return list<string> */
    private function warnings(MarketBootstrapProfile $profile): array
    {
        $warnings = [
            'Auction terms and support/payment recipient details are test placeholders and must be replaced before commercial launch.',
        ];

        if (! config('content_review.enabled')) {
            $warnings[] = 'CONTENT_REVIEW_ENABLED is false; set it to true and rebuild the config cache to run Gemini reviews.';
        }

        if (! $this->contentReviewProviders->isConfigured('gemini')) {
            $warnings[] = 'Gemini is not configured; verify GEMINI_API_KEY before testing content review.';
        }

        foreach ($profile->paymentMethods() as $method) {
            if (($method['channel'] ?? null) === 'online' && ($method['is_active'] ?? false) !== true) {
                $warnings[] = "Payment method {$method['code']} was defined but remains inactive until its provider credentials are configured.";
            }
        }

        return $warnings;
    }

    /** @return array{resource: string, action: string, details: string} */
    private function operation(string $resource, string $action, string $details): array
    {
        return compact('resource', 'action', 'details');
    }

    private function modelMatches(object $model, array $desired): bool
    {
        foreach ($desired as $key => $expected) {
            $actual = $model->{$key};

            if ($actual instanceof BackedEnum) {
                $actual = $actual->value;
            }

            if (! $this->same($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function same(mixed $first, mixed $second): bool
    {
        return $this->normalize($first) === $this->normalize($second);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}

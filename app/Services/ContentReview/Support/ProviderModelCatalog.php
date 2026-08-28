<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\DTO\ContentReview\ProviderModelDescriptor;

final class ProviderModelCatalog
{
    /**
     * @return array<int, string>
     */
    public function models(string $provider): array
    {
        return array_map(
            static fn (ProviderModelDescriptor $descriptor): string => $descriptor->id,
            $this->descriptors($provider)
        );
    }

    public function descriptor(string $provider, string $model): ?ProviderModelDescriptor
    {
        return ProviderModelDescriptor::fromArray($model, $this->modelEntries($provider)[$model] ?? null);
    }

    public function has(string $provider, string $model): bool
    {
        return $this->descriptor($provider, $model) !== null;
    }

    /**
     * The descriptor to bill a call against, which is not always the one selection would accept.
     *
     * A vendor may answer an alias with the concrete build it resolved to — the catalog entry
     * with a version suffix appended. Pricing that as "unknown model" silently drops the cost of
     * a call that really happened, so a versioned id falls back to the entry it was built from.
     * Selection stays strict on purpose: a mistyped configured model must still be rejected
     * rather than quietly priced as something else.
     */
    public function pricingDescriptor(string $provider, string $model): ?ProviderModelDescriptor
    {
        $exact = $this->descriptor($provider, $model);

        if ($exact !== null) {
            return $exact;
        }

        $base = $this->versionedBase($provider, $model);

        return $base === null ? null : $this->descriptor($provider, $base);
    }

    /**
     * The longest catalog id the given model is a versioned variant of. Longest wins so a
     * `-lite-preview` build bills as `-lite` rather than as the shorter family it also prefixes.
     */
    private function versionedBase(string $provider, string $model): ?string
    {
        $best = null;

        foreach (array_keys($this->modelEntries($provider)) as $id) {
            $id = (string) $id;

            if ($id === '' || ! str_starts_with($model, $id)) {
                continue;
            }

            // Only a version separator counts, so `...-5` never swallows `...-50`.
            if (preg_match('/^[-@:_]/', substr($model, strlen($id))) !== 1) {
                continue;
            }

            if ($best === null || strlen($id) > strlen($best)) {
                $best = $id;
            }
        }

        return $best;
    }

    public function defaultModel(string $provider): ?string
    {
        $configured = $this->providerConfig($provider)['default_model'] ?? null;

        if (is_string($configured) && $this->has($provider, $configured)) {
            return $configured;
        }

        return $this->models($provider)[0] ?? null;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function toArray(): array
    {
        $catalog = [];

        foreach (array_keys($this->config()) as $provider) {
            $catalog[$provider] = array_map(
                static fn (ProviderModelDescriptor $descriptor): array => $descriptor->toArray(),
                $this->descriptors((string) $provider)
            );
        }

        return $catalog;
    }

    /**
     * @return array<int, ProviderModelDescriptor>
     */
    private function descriptors(string $provider): array
    {
        $descriptors = [];

        foreach ($this->modelEntries($provider) as $id => $entry) {
            $descriptor = ProviderModelDescriptor::fromArray((string) $id, $entry);

            if ($descriptor !== null) {
                $descriptors[] = $descriptor;
            }
        }

        return $descriptors;
    }

    private function modelEntries(string $provider): array
    {
        $models = $this->providerConfig($provider)['models'] ?? null;

        return is_array($models) ? $models : [];
    }

    private function providerConfig(string $provider): array
    {
        $config = $this->config()[$provider] ?? null;

        return is_array($config) ? $config : [];
    }

    private function config(): array
    {
        $providers = config('content_review.providers');

        return is_array($providers) ? $providers : [];
    }
}

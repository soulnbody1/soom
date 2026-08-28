<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\DTO\ContentReview\ProviderModelDescriptor;

final class ProviderModelCatalog
{
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

    public function pricingDescriptor(string $provider, string $model): ?ProviderModelDescriptor
    {
        $exact = $this->descriptor($provider, $model);

        if ($exact !== null) {
            return $exact;
        }

        $base = $this->versionedBase($provider, $model);

        return $base === null ? null : $this->descriptor($provider, $base);
    }

    private function versionedBase(string $provider, string $model): ?string
    {
        $best = null;

        foreach (array_keys($this->modelEntries($provider)) as $id) {
            $id = (string) $id;

            if ($id === '' || ! str_starts_with($model, $id)) {
                continue;
            }

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

<?php

namespace App\Models\Concerns;

use App\Models\Market;
use App\Models\Scopes\MarketScope;
use App\Support\Market\MarketContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToMarket
{
    public static function bootBelongsToMarket(): void
    {
        static::addGlobalScope(new MarketScope);

        static::creating(function ($model): void {
            $context = app(MarketContext::class);
            $state = $context->state();

            if (! $state->mode->requiresMarket()) {
                if ($model->marketIsOptional() && $model->getAttribute('market_id') === null) {
                    return;
                }

                throw new LogicException('A market-scoped model cannot be created from a global context.');
            }

            if ($model->getAttribute('market_id') === null) {
                $model->setAttribute('market_id', $state->market->getKey());
            }

            if ((int) $model->getAttribute('market_id') !== (int) $state->market->getKey()) {
                throw new LogicException('A market-scoped model cannot be created in another market.');
            }
        });

        static::updating(function ($model): void {
            if (app(MarketContext::class)->state()->mode->isGlobal()) {
                if ($model->marketIsOptional() && $model->getOriginal('market_id') === null) {
                    return;
                }

                throw new LogicException('A market-scoped model cannot be updated from a global context.');
            }

            if ($model->isDirty('market_id')) {
                throw new LogicException('A market-scoped model cannot change market.');
            }
        });

        static::deleting(function ($model): void {
            if (app(MarketContext::class)->state()->mode->isGlobal()) {
                if ($model->marketIsOptional() && $model->getAttribute('market_id') === null) {
                    return;
                }

                throw new LogicException('A market-scoped model cannot be deleted from a global context.');
            }
        });
    }

    public function marketIsOptional(): bool
    {
        return false;
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}

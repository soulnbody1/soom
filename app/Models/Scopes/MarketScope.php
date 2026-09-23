<?php

namespace App\Models\Scopes;

use App\Support\Market\MarketContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class MarketScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $state = app(MarketContext::class)->state();

        if ($state->mode->requiresMarket()) {
            $builder->where($model->qualifyColumn('market_id'), $state->market->getKey());
        }
    }
}

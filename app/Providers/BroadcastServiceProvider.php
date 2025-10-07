<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Broadcast::routes(['prefix' => 'api','middleware' => ['auth:sanctum']]);
        require base_path('routes/channels.php');
    }
}

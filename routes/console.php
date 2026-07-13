<?php

use App\Jobs\Auction\DispatchAuctionOutboxJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
app(Schedule::class)->command('reels:cleanup')->hourly();
app(Schedule::class)->command('auction:run-operations')->everyMinute()->withoutOverlapping();
app(Schedule::class)->job(new DispatchAuctionOutboxJob)->everyMinute()->withoutOverlapping();
app(Schedule::class)->command('auction:reconcile')->everyFifteenMinutes()->withoutOverlapping();

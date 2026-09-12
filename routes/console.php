<?php

use App\Jobs\Auction\DispatchAuctionOutboxJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

app(Schedule::class)->command('reels:cleanup')->hourly()->withoutOverlapping();
app(Schedule::class)->command('notifications:prune')->dailyAt('03:15')->withoutOverlapping();
app(Schedule::class)->command('auction:run-operations')->everyMinute()->withoutOverlapping();
app(Schedule::class)->command('auction:run-deadlines')->everyMinute()->withoutOverlapping();
app(Schedule::class)->job(new DispatchAuctionOutboxJob)->everyMinute()->withoutOverlapping();
app(Schedule::class)->command('auction:reconcile')->everyFifteenMinutes()->withoutOverlapping();
app(Schedule::class)->command('content-review:dispatch-pending')->everyMinute()->withoutOverlapping();
app(Schedule::class)->command('content-review:sweep-alerts')->everyFiveMinutes()->withoutOverlapping();

app(Schedule::class)->command('sanctum:prune-expired --hours=24')->dailyAt('03:30')->withoutOverlapping();
app(Schedule::class)->command('auth:clear-resets')->dailyAt('03:35')->withoutOverlapping();
app(Schedule::class)->command('queue:prune-failed --hours=336')->dailyAt('03:40')->withoutOverlapping();
app(Schedule::class)->command('queue:prune-batches --hours=336')->dailyAt('03:45')->withoutOverlapping();

if (class_exists(\Laravel\Telescope\Telescope::class) && config('telescope.enabled')) {
    app(Schedule::class)->command('telescope:prune --hours=48')->dailyAt('03:50')->withoutOverlapping();
}

<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'old_password',
            'otp',
            'token',
            'refresh_token',
            'fcm_token',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user): bool {
            if ($this->app->environment('production')) {
                return false;
            }

            return in_array((string) $user->email, self::allowedEmails(), true);
        });
    }

    private static function allowedEmails(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('telescope.allowed_emails'))
        )));
    }
}

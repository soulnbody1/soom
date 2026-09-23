<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class HttpBootstrapTest extends TestCase
{
    /**
     * public/index.php resolves the HTTP kernel straight off a freshly required
     * bootstrap/app.php, before anything has loaded the configuration. Anything
     * eager in withMiddleware() therefore dies on every real request while the
     * ordinary test harness, which bootstraps first, stays green.
     */
    public function test_the_http_kernel_resolves_before_configuration_is_loaded(): void
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';

        $this->assertInstanceOf(Application::class, $app);
        $this->assertFalse($app->bound('config'), 'Configuration is loaded later than this point.');

        $kernel = $app->make(HttpKernel::class);

        $this->assertInstanceOf(HttpKernel::class, $kernel);
    }
}

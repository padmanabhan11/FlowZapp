<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\ServiceProvider;

/**
 * Registers CurrentWorkspace as a singleton so middleware, models and jobs
 * share one resolved tenant per request / job. Under Octane or a long-running
 * worker the singleton is reset between requests by the framework's container
 * flush; jobs set it explicitly via CurrentWorkspace::runAs().
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentWorkspace::class);
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Adds support for running queued tenant jobs in batches.
 *
 * @deprecated Doesn't seem to 1. be necessary, 2. work correctly in Laravel 11. Please don't use this bootstrapper, the class will be removed before release.
 */
class JobBatchBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        protected Application $app,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->deprecatedNotice();
    }

    protected function deprecatedNotice(): void
    {
        if ($this->app->environment() == 'local' && $this->app->hasDebugModeEnabled()) {
            throw new Exception("JobBatchBootstrapper is not supported anymore, please remove it from your tenancy config. Job batches should work out of the box in Laravel 11. If they don't, please open a bug report.");
        }
    }

    public function revert(): void
    {
        $this->deprecatedNotice();
    }
}

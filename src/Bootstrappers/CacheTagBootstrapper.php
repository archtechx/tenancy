<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Stancl\Tenancy\CacheManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class CacheTagBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        CacheManager::$addTags = true;
    }

    public function revert(): void
    {
        CacheManager::$addTags = false;
    }
}

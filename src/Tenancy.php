<?php

namespace Stancl\Tenancy;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Tenant;

class Tenancy
{
    /** @var Tenant|null */
    public $tenant;

    /** @var callable|null */
    public static $getBootstrappers = null;

    public function initialize(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        event(new Events\TenancyInitialized($tenant));
    }

    public function end(): void
    {
        event(new Events\TenancyEnded($this->tenant));

        $this->tenant = null;
    }

    /** @return TenancyBootstrapper[] */
    public function getBootstrappers(): array
    {
        $resolve = static::$getBootstrappers ?? function (Tenant $tenant) {
            return config('tenancy.bootstrappers');
        };

        return $resolve($this->tenant);
    }
}

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

    /** @var bool */
    public $initialized = false;

    public function initialize(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->initialized = true;

        event(new Events\TenancyInitialized($tenant));
    }

    public function end(): void
    {
        $this->initialized = false;

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

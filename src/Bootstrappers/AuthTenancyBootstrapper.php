<?php

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Support\Facades\App;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class AuthTenancyBootstrapper implements TenancyBootstrapper
{

    public function bootstrap(Tenant $tenant)
    {
        // empty
    }

    public function revert()
    {
        App::forgetInstance('auth.password');
    }
}

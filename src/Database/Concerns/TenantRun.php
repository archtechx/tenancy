<?php

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Tenant;

trait TenantRun
{
    public function run(callable $callback)
    {
        /** @var Tenant $this */

        $originalTenant = tenant();

        tenancy()->initialize($this);
        $result = $callback($this);

        if ($originalTenant) {
            tenancy()->initialize($originalTenant);
        } else {
            tenancy()->end();
        }

        return $result;
    }
}

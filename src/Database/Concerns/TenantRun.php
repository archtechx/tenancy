<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Closure;
use Stancl\Tenancy\Contracts\Tenant;

trait TenantRun
{
    /**
     * Run a callback in this tenant's context.
     *
     * This method is atomic and safely reverts to the previous context.
     */
    public function run(Closure $callback): mixed
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

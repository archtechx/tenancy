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
     *
     * @template T
     * @param Closure(Tenant): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        /** @var Tenant $this */
        return tenancy()->run($this, $callback);
    }
}

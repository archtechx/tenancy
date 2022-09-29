<?php

namespace Stancl\Tenancy\Database\Concerns;

/**
 * @mixin \Stancl\Tenancy\Contracts\Tenant
 */
trait InitializationHelpers
{
    public function enter(): void
    {
        tenancy()->initialize($this);
    }

    public function leave(): void
    {
        tenancy()->end();
    }
}

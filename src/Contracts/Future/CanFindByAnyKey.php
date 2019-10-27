<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts\Future;

use Stancl\Tenancy\Exceptions\TenantDoesNotExistException;
use Stancl\Tenancy\Tenant;

/**
 * This interface *might* be part of the StorageDriver interface in 3.x.
 */
interface CanFindByAnyKey
{
    /**
     * Find a tenant using an arbitrary key.
     *
     * @param string $key
     * @param mixed $value
     * @return Tenant
     * @throws TenantDoesNotExistException
     */
    public function findBy(string $key, $value): Tenant;
}

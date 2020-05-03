<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Facades;

use Illuminate\Support\Facades\Facade;
use Stancl\Tenancy\Tenant as TenantObject;

class Tenant extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TenantObject::class;
    }

    public static function create($domains, array $data = []): TenantObject
    {
        return TenantObject::create($domains, $data);
    }
}

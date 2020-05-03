<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Facades;

use Illuminate\Support\Facades\Facade;

class Tenant extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Tenant::class;
    }

    public static function create($domains, array $data = []): \Stancl\Tenancy\Tenant
    {
        return Tenant::create($domains, $data);
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Stancl\Tenancy\Database\Concerns;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Contracts\SingleDomainTenant as SingleDomainTenantContract;

class SingleDomainTenant extends BaseTenant implements SingleDomainTenantContract, TenantWithDatabase
{
    use Concerns\ConvertsDomainsToLowercase, Concerns\HasDatabase;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'domain',
        ];
    }
}

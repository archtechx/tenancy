<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Database\Concerns;
use Illuminate\Database\Eloquent\Model;
use Stancl\VirtualColumn\VirtualColumn;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;
use Stancl\Tenancy\Contracts\SingleDomainTenant as SingleDomainTenantContract;

class SingleDomainTenant extends BaseTenant implements SingleDomainTenantContract, TenantWithDatabase
{
    use Concerns\ConvertsDomainsToLowercase, Concerns\HasDatabase;

    public function getCustomColumns(): array
    {
        return [
            'id',
            'domain',
        ];
    }
}

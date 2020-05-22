<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models;

class Tenant extends Models\Tenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;
}

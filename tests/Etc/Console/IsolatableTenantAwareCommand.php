<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class IsolatableTenantAwareCommand extends Command implements Isolatable
{
    use TenantAwareCommand, HasTenantOptions;

    protected $signature = 'tenant-aware:isolatable';

    public function __invoke(): void
    {
        $this->line('Ran for tenant ' . tenant()->getTenantKey());
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class FailingTenantAwareCommand extends Command
{
    use TenantAwareCommand, HasTenantOptions;

    protected $signature = 'tenant-aware:fail';

    public function handle(): void
    {
        $this->fail('Failed for tenant ' . tenant()->getTenantKey());
    }
}

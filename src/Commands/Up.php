<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasTenantOptions;

class Up extends Command
{
    use HasTenantOptions;

    protected $signature = 'tenants:up';

    protected $description = 'Put tenants out of maintenance mode.';

    public function handle(): int
    {
        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");
            $tenant->bringUpFromMaintenance();
        });

        $this->components->info('Tenants are now out of maintenance mode.');

        return 0;
    }
}

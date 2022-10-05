<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;

class Up extends Command
{
    use HasATenantsOption;

    protected $signature = 'tenants:up';

    protected $description = 'Put tenants out of maintenance mode.';

    public function handle(): void
    {
        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            $this->line("Tenant: {$tenant['id']}");
            $tenant->bringUpFromMaintenance();
        });

        $this->comment('Tenants are now out of maintenance mode.');
    }
}

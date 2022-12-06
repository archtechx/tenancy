<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class CreatePendingTenants extends Command
{
    protected $signature = 'tenants:pending-create {--count= : The number of pending tenants to be created}';

    protected $description = 'Create pending tenants.';

    public function handle(): int
    {
        $this->info('Creating pending tenants.');

        $maxPendingTenantCount = (int) ($this->option('count') ?? config('tenancy.pending.count'));
        $pendingTenantCount = $this->getPendingTenantCount();
        $createdCount = 0;

        while ($pendingTenantCount < $maxPendingTenantCount) {
            tenancy()->model()::createPending();

            // Fetching the pending tenant count in each iteration prevents creating too many tenants
            // If pending tenants are being created somewhere else while running this command
            $pendingTenantCount = $this->getPendingTenantCount();

            $createdCount++;
        }

        $this->info($createdCount . ' pending ' . str('tenant')->plural($createdCount) . ' created.');
        $this->info($maxPendingTenantCount . ' pending ' . str('tenant')->plural($maxPendingTenantCount) . ' ready to be used.');

        return 0;
    }

    /** Calculate the number of currently available pending tenants. */
    protected function getPendingTenantCount(): int
    {
        return tenancy()->query()
            ->onlyPending()
            ->count();
    }
}

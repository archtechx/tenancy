<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class CreatePendingTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:pending {--count= : The number of tenants to be in a pending state}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy tenants until the pending count is achieved.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Deploying pending tenants.');

        $maxPendingTenantCount = (int) ($this->option('count') ?? config('tenancy.pending.count'));

        $pendingTenantCount = $this->getPendingTenantCount();

        $deployedCount = 0;
        while ($pendingTenantCount < $maxPendingTenantCount) {
            tenancy()->model()::createPending();
            // Update the number of pending tenants every time with a query to get a live count
            // To prevent deploying too many tenants if pending tenants are being created simultaneously somewhere else
            // While running this command
            $pendingTenantCount = $this->getPendingTenantCount();
            $deployedCount++;
        }

        $this->info($deployedCount . ' ' . str('tenant')->plural($deployedCount) . ' deployed.');
        $this->info($maxPendingTenantCount . ' ' . str('tenant')->plural($maxPendingTenantCount) . ' ready to be used.');

        return 1;
    }

    /**
     * Calculate the number of currently deployed pending tenants.
     */
    private function getPendingTenantCount(): int
    {
        return tenancy()
            ->query()
            ->onlyPending()
            ->count();
    }
}

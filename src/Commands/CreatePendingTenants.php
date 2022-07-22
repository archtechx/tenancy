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
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Deploying pending tenants.');

        $pendingObjectifCount = (int) ($this->option('count') ?? config('tenancy.pending.count'));

        $pendingCurrentCount = $this->getPendingTenantCount();

        $deployedCount = 0;
        while ($pendingCurrentCount < $pendingObjectifCount) {
            tenancy()->model()::createPending();
            // Update the number of pending tenants every time with a query to get a live count
            // To prevent deploying too many tenants if pending tenants are being created simultaneously somewhere else
            // While running this command
            $pendingCurrentCount = $this->getPendingTenantCount();
            $deployedCount++;
        }

        $this->info($deployedCount . ' ' . str('tenant')->plural($deployedCount) . ' deployed.');
        $this->info($pendingObjecifCount . ' ' . str('tenant')->plural($pendingObjectifCount) . ' ready to be used.');

        return 1;
    }

    /**
     * Calculate the number of currently deployed pending tenants.
     *
     * @return int
     */
    private function getPendingTenantCount(): int
    {
        return tenancy()
            ->query()
            ->onlyPending()
            ->count();
    }
}

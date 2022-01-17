<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class CreateReadiedTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:readied {--count= The number of tenant to be in a readied state}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy tenants until the readied count is achieved.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Deploying readied tenants.');

        $readiedCountObjectif = (int)config('tenancy.readied.count');

        $readiedTenantCount = $this->getReadiedTenantCount();

        $deployedCount = 0;
        while ($readiedTenantCount < $readiedCountObjectif) {
            tenancy()->model()::createReadied();
            // We update the number of readied tenant every time with a query to get a live count.
            // this prevents to deploy too many tenants if readied tenants have been deployed
            // while this command is running.
            $readiedTenantCount = $this->getReadiedTenantCount();
            $deployedCount++;
        }

        $this->info("$deployedCount tenants deployed, $readiedCountObjectif tenant(s) are ready to be used.");
    }

    /**
     * Calculates the number of readied tenants currently deployed
     * @return int
     */
    private function getReadiedTenantCount(): int
    {
        return tenancy()
            ->query()
            ->onlyReadied()
            ->count();
    }
}

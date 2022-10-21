<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ClearPendingTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:pending-clear
                            {--all : Override the default settings and deletes all pending tenants}
                            {--older-than-days= : Deletes all pending tenants older than the amount of days}
                            {--older-than-hours= : Deletes all pending tenants older than the amount of hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove pending tenants.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Removing pending tenants.');

        $expirationDate = now();
        // We compare the original expiration date to the new one to check if the new one is different later
        $originalExpirationDate = $expirationDate->copy()->toImmutable();

        // Skip the time constraints if the 'all' option is given
        if (! $this->option('all')) {
            $olderThanDays = $this->option('older-than-days');
            $olderThanHours = $this->option('older-than-hours');

            if ($olderThanDays && $olderThanHours) {
                $this->line("<options=bold,reverse;fg=red> Cannot use '--older-than-days' and '--older-than-hours' together \n");
                $this->line('Please, choose only one of these options.');

                return 1; // Exit code for failure
            }

            if ($olderThanDays) {
                $expirationDate->subDays($olderThanDays);
            }

            if ($olderThanHours) {
                $expirationDate->subHours($olderThanHours);
            }
        }

        $deletedTenantCount = tenancy()
            ->query()
            ->onlyPending()
            ->when($originalExpirationDate->notEqualTo($expirationDate), function (Builder $query) use ($expirationDate) {
                $query->where($query->getModel('pending_since'), '<', $expirationDate->timestamp);
            })
            ->get()
            ->each // Trigger the model events by deleting the tenants one by one
            ->delete()
            ->count();

        $this->info($deletedTenantCount . ' pending ' . str('tenant')->plural($deletedTenantCount) . ' deleted.');
    }
}

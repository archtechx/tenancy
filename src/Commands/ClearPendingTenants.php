<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ClearPendingTenants extends Command
{
    protected $signature = 'tenants:pending-clear
                            {--older-than-days= : Deletes all pending tenants older than the amount of days}
                            {--older-than-hours= : Deletes all pending tenants older than the amount of hours}';

    protected $description = 'Remove pending tenants.';

    public function handle(): int
    {
        $this->components->info('Removing pending tenants.');

        $expirationDate = now();
        // We compare the original expiration date to the new one to check if the new one is different later
        $originalExpirationDate = $expirationDate->copy()->toImmutable();

        $olderThanDays = (int) $this->option('older-than-days');
        $olderThanHours = (int) $this->option('older-than-hours');

        if ($olderThanDays && $olderThanHours) {
            $this->components->error("Cannot use '--older-than-days' and '--older-than-hours' together. Please, choose only one of these options.");

            return 1; // Exit code for failure
        }

        if ($olderThanDays) {
            $expirationDate->subDays($olderThanDays);
        }

        if ($olderThanHours) {
            $expirationDate->subHours($olderThanHours);
        }

        $deletedTenantCount = tenancy()->query()
            ->onlyPending()
            ->when($originalExpirationDate->notEqualTo($expirationDate), function (Builder $query) use ($expirationDate) {
                $query->where($query->getModel()->getColumnForQuery('pending_since'), '<', $expirationDate->timestamp);
            })
            ->get()
            ->each // Trigger the model events by deleting the tenants one by one
            ->delete()
            ->count();

        $this->components->info($deletedTenantCount . ' pending ' . str('tenant')->plural($deletedTenantCount) . ' deleted.');

        return 0;
    }
}

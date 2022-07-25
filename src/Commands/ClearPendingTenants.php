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
                            {--older-days= : Deletes all pending tenants older than the amount of days}
                            {--older-hours= : Deletes all pending tenants older than the amount of hours}';

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
        $this->info('Cleaning pending tenants.');

        $expirationDate = now();
        // We compare the original expiration date to the new one to check if the new one is different later
        $originalExpirationDate = $expirationDate->copy()->toImmutable();

        // If the 'all' option is given, skip the expiry date configuration
        if (! $this->option('all')) {
            if ($olderThanDays = $this->option('older-days') ?? config('tenancy.pending.older_than_days')) {
                $expirationDate->subDays($olderThanDays);
            }

            if ($olderThanHours = $this->option('older-hours') ?? config('tenancy.pending.older_than_hours')) {
                $expirationDate->subHours($olderThanHours);
            }
        }

        $deletedPendingCount = tenancy()
            ->query()
            ->onlyPending()
            ->when($originalExpirationDate->notEqualTo($expirationDate), function (Builder $query) use ($expirationDate) {
                $query->where('data->pending_since', '<', $expirationDate->timestamp);
            })
            ->get()
            ->each // Trigger the model events by deleting the tenants one by one
            ->delete()
            ->count();

        $this->info($deletedPendingCount . ' pending ' . str('tenant')->plural($deletedPendingCount) . ' deleted.');
    }
}

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
                            {--older-days= : Deletes all pending older than the amount of days}
                            {--older-hours= : Deletes all pending older than the amount of hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes any pending tenants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Cleaning pending tenants.');

        $expireDate = now();
        // At the end, we will check if the value has been changed by comparing the two dates
        $savedExpiredDate = $expireDate->copy()->toImmutable();

        // If the all option is given, skip the expiry date configuration
        if (! $this->option('all')) {
            if ($olderThanDays = $this->option('older-days') ?? config('tenancy.pending.older_than_days')) {
                $expireDate->subDays($olderThanDays);
            }

            if ($olderThanHours = $this->option('older-hours') ?? config('tenancy.pending.older_than_hours')) {
                $expireDate->subHours($olderThanHours);
            }
        }

        $deletedPendingCount = tenancy()
            ->query()
            ->onlyPending()
            ->when($savedExpiredDate->notEqualTo($expireDate), function (Builder $query) use ($expireDate) {
                $query->where('data->pending_since', '<', $expireDate->timestamp);
            })
            ->get()
            ->each // Make sure the model events are triggered by deleting the tenants one by one
            ->delete()
            ->count();

        $this->info($deletedPendingCount . ' pending ' . str('tenant')->plural($deletedPendingCount) . ' deleted.');
    }
}

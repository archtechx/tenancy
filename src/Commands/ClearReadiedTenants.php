<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ClearReadiedTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:readied-clear
                            {--all : Override the default settings and deletes all readied tenants}
                            {--older-days= : Deletes all readied older than the amount of days}
                            {--older-hours= : Deletes all readied older than the amount of hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes any readied tenants';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Cleaning readied tenants.');

        $expireDate = now();
        // At the end, we will check if the value has been changed by comparing the two dates
        $savedExpiredDate = $expireDate->copy()->toImmutable();

        // If the all option is given, skip the expiry date configuration
        if (! $this->option('all')) {
            if ($olderThanDays = $this->option('older-days') ?? config('tenancy.readied.older_than_days')) {
                $expireDate->subDays($olderThanDays);
            }

            if ($olderThanHours = $this->option('older-hours') ?? config('tenancy.readied.older_than_hours')) {
                $expireDate->subHours($olderThanHours);
            }
        }


        $readiedTenantsDeletedCount = tenancy()
            ->query()
            ->onlyReadied()
            ->when($savedExpiredDate->notEqualTo($expireDate), function (Builder $query) use ($expireDate) {
                $query->where('data->readied', '<', $expireDate->timestamp);
            })
            ->get()
            ->each // This makes sure the events or triggered on the model
            ->delete()
            ->count();

        $this->info("$readiedTenantsDeletedCount readied tenant(s) deleted.");
    }
}

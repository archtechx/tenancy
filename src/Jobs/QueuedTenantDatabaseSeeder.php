<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Tenant;

class QueuedTenantDatabaseSeeder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string */
    protected $tenantId;

    /** @var array */
    protected $seederClassParameter = [];

    public function __construct(Tenant $tenant, $seederClassName = '')
    {
        $this->tenantId = $tenant->id;
        $this->seederClassParameter = ! empty($seederClassName) ? ['--class' => $seederClassName] : [];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Artisan::call('tenants:seed', [
            '--tenants' => [$this->tenantId],
        ] + $this->seederClassParameter);
    }
}

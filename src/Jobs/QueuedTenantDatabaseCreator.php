<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Tenant;

class QueuedTenantDatabaseCreator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantDatabaseManager */
    protected $databaseManager;

    /** @var Tenant */
    protected $tenant;

    public function __construct(TenantDatabaseManager $databaseManager, Tenant $tenant)
    {
        $this->databaseManager = $databaseManager;
        $this->tenant = $tenant;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->databaseManager->createDatabase($this->tenant);
    }
}

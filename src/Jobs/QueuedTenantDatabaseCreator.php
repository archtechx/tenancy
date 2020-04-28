<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Tenant;

class QueuedTenantDatabaseCreator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantDatabaseManager */
    protected $databaseManager;

    /** @var string */
    protected $databaseName;

    /** @var Tenant */
    public $tenant;

    /**
     * Create a new job instance.
     *
     * @param TenantDatabaseManager $databaseManager
     * @param string $databaseName
     * @param Tenant $tenant
     */
    public function __construct(TenantDatabaseManager $databaseManager, string $databaseName, Tenant $tenant)
    {
        $this->databaseManager = $databaseManager;
        $this->databaseName = $databaseName;
        $this->tenant = $tenant;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->databaseManager->createDatabase($this->databaseName, $this->tenant);
    }
}

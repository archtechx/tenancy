<?php

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Stancl\Tenancy\Interfaces\TenantDatabaseManager;

class QueuedTenantDatabaseDeleter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $databaseManager;
    protected $databaseName;

    /**
     * Create a new job instance.
     *
     * @param DatabaseCreator $databaseCreator
     * @param string $databaseName
     * @param string $action
     * @return void
     */
    public function __construct(TenantDatabaseManager $databaseManager, string $databaseName)
    {
        $this->databaseManager = $databaseManager;
        $this->databaseName = $databaseName;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->databaseManager->deleteDatabase($this->databaseName);
    }
}

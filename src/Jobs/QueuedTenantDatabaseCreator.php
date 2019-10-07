<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class QueuedTenantDatabaseCreator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantDatabaseManager */
    protected $databaseManager;

    /** @var string */
    protected $databaseName;

    /**
     * Create a new job instance.
     *
     * @param TenantDatabaseManager $databaseManager
     * @param string $databaseName
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
        $this->databaseManager->createDatabase($this->databaseName);
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\CreatingDatabase;
use Stancl\Tenancy\Events\DatabaseCreated;

class CreateDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantWithDatabase|Model */
    protected $tenant;

    public function __construct(TenantWithDatabase $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(DatabaseManager $databaseManager)
    {
        // update the connection to use the tenant's config
        $databaseManager->createHostConnection($this->tenant);

        event(new CreatingDatabase($this->tenant));

        // Terminate execution of this job & other jobs in the pipeline
        if ($this->tenant->getInternal('create_database') === false) {
            return false;
        }

        $databaseManager->ensureTenantCanBeCreated($this->tenant);
        $this->tenant->database()->makeCredentials();
        $this->tenant->database()->manager()->createDatabase($this->tenant);

        event(new DatabaseCreated($this->tenant));

        // revert the configuration to the original template
        $databaseManager->resetTenantConnection($this->tenant);
    }
}

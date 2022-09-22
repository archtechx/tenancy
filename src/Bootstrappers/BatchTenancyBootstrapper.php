<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class BatchTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * The previous database connection instance.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $previousConnection = null;

    public function __construct(protected DatabaseBatchRepository $databaseBatchRepository)
    {
    }

    public function bootstrap(Tenant $tenant)
    {
        // Update batch repository connection to use the tenant connection
        $this->previousConnection = $this->databaseBatchRepository->getConnection();
        $this->databaseBatchRepository->setConnection(DB::connection('tenant'));
    }

    public function revert()
    {
        if ($this->previousConnection) {
            // Replace batch repository connection with the previously replaced one
            $this->databaseBatchRepository->setConnection($this->previousConnection);
            $this->previousConnection = null;
        }
    }
}

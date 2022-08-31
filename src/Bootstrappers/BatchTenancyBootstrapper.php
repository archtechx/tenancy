<?php

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class BatchTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * The database previous connection instance.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $previousConnection = null;

    public function bootstrap(Tenant $tenant)
    {
        $batchRepository = app(BatchRepository::class);

        if ($batchRepository instanceof DatabaseBatchRepository) {
            // Access the resolved batch repository instance and update its connection to use the tenant connection
            $this->previousConnection = $batchRepository->getConnection();
            $batchRepository->setConnection(DB::connection('tenant'));
        }
    }

    public function revert()
    {
        if ($this->previousConnection) {
             // Access the resolved batch repository instance and replace its connection with the previously replaced one
            $batchRepository = app(BatchRepository::class);
            $batchRepository->setConnection($this->previousConnection);
            $this->previousConnection = null;
        }
    }
}
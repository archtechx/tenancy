<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Adds support for running queued tenant jobs in batches.
 */
class JobBatchBootstrapper implements TenancyBootstrapper
{
    /**
     * The previous database connection instance.
     */
    protected ?Connection $previousConnection = null;

    public function __construct(
        protected DatabaseBatchRepository $batchRepository,
        protected DatabaseManager $databaseManager
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        // Update batch repository connection to use the tenant connection
        $this->previousConnection = $this->batchRepository->getConnection();
        $this->batchRepository->setConnection($this->databaseManager->connection('tenant'));
    }

    public function revert(): void
    {
        if ($this->previousConnection) {
            // Replace batch repository connection with the previously replaced one
            $this->batchRepository->setConnection($this->previousConnection);
            $this->previousConnection = null;
        }
    }
}

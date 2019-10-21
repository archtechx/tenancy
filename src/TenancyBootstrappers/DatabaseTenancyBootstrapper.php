<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Exceptions\TenantDatabaseDoesNotExistException;
use Stancl\Tenancy\Tenant;

class DatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var DatabaseManager */
    protected $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    public function start(Tenant $tenant)
    {
        $database = $tenant->getDatabaseName();
        if (! $this->database->getTenantDatabaseManager($tenant)->databaseExists($database)) {
            throw new TenantDatabaseDoesNotExistException($database);
        }

        $this->database->connect($tenant);
    }

    public function end()
    {
        $this->database->reconnect();
    }
}

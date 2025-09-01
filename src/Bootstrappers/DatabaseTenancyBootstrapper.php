<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Exception;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;

class DatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var DatabaseManager */
    protected $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    public function bootstrap(Tenant $tenant): void
    {
        /** @var TenantWithDatabase $tenant */
        if (data_get($tenant->database()->getTemplateConnection(), 'url')) {
            // The package works with individual parts of the database connection config, so DATABASE_URL is not supported.
            // When DATABASE_URL is set, this bootstrapper can silently fail i.e. keep using the template connection's database URL
            // which takes precedence over individual segments of the connection config. This issue can be hard to debug as it can be
            // production-specific. Therefore, we throw an exception (that effectively blocks all tenant pages) to prevent incorrect DB use.
            throw new Exception('The template connection must NOT have URL defined. Specify the connection using individual parts instead of a database URL.');
        }

        // Better debugging, but breaks cached lookup, so we disable this in prod
        if (app()->environment('local') || app()->environment('testing')) {
            $database = $tenant->database()->getName();
            if (! $tenant->database()->manager()->databaseExists($database)) {
                throw new TenantDatabaseDoesNotExistException($database);
            }
        }

        $this->database->connectToTenant($tenant);
    }

    public function revert(): void
    {
        $this->database->reconnectToCentral();
    }
}

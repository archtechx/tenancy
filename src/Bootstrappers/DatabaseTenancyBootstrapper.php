<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

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

        // Better debugging, but breaks cached lookup in prod
        if (app()->environment('local') || app()->environment('testing')) { // todo@docs mention this change in v4 upgrade guide https://github.com/archtechx/tenancy/pull/945#issuecomment-1268206149
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

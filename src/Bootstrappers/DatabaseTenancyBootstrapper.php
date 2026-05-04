<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Exception;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Illuminate\Support\Facades\DB;

class DatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * When true, throw an exception if a tenant gets connected to
     * another tenant's database or to the central database.
     */
    public static bool $harden = false;

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

        if (static::$harden) {
            try {
                $this->harden($tenant);
            } catch (RuntimeException $e) {
                // Revert connection back to central
                $this->revert();

                throw $e;
            }
        }
    }

    public function revert(): void
    {
        $this->database->reconnectToCentral();
    }

    protected function harden(Tenant $tenant): void
    {
        $dbName = DB::getDatabaseName();

        // Check if any other tenant uses this tenant's database
        if ($tenant::where($tenant->getTenantKeyName(), '!=', $tenant->getTenantKey())
            ->where('data->tenancy_db_name', $dbName)
            ->exists()) {
            throw new RuntimeException('Tenant cannot use a database of another tenant.');
        }

        // Check if the current database doesn't have the tenants table (i.e. it's not the central database)
        if (Schema::hasTable($tenant->getTable())) {
            throw new RuntimeException('Tenant cannot use the central database.');
        }
    }
}

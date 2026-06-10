<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Throwable;

class DatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * When true, throw an exception if a tenant gets connected to
     * another tenant's database or to the central database.
     *
     * This case should never come up in well-configured apps where
     * users cannot set or edit tenant IDs or database names, so this
     * option is disabled by default.
     *
     * However, applications dealing with extremely sensitive data may
     * choose to enable this runtime check to prevent a bug or misconfiguration
     * from creating an exploit that would let an attacker access another
     * tenant's data or data from the central database.
     *
     * One way such a scenario might come up is if an application allows
     * broad tenant attribute updates on a page for updating some fields
     * on the tenant, without restricting that action to only a limited
     * set of fields that are safe to edit. An attacker might be able to add
     * something like ['tenancy_db_name' => '...'] to the request which could
     * lead to this internal attribute being updated on an existing tenant.
     *
     * It's possible that enabling this setting will negate the performance
     * benefits of cached tenant lookup.
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
                $this->verifyTenantCanUseDatabase($tenant);
            } catch (Throwable $e) {
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

    protected function verifyTenantCanUseDatabase(Tenant $tenant): void
    {
        /** @var \Stancl\Tenancy\Database\Models\Tenant&TenantWithDatabase $tenant */

        $tenantDbName = $tenant->database()->getName();

        // Check that no other tenant uses this tenant's database
        if ($tenant::where($tenant->getTenantKeyName(), '!=', $tenant->getTenantKey())
            ->where($tenant::getDataColumn() . '->' . $tenant->internalPrefix() . 'db_name', $tenantDbName)
            ->exists()) {
            throw new RuntimeException('Tenant cannot use a database of another tenant.');
        }

        $centralDbName = DB::connection(
            config('tenancy.database.central_connection', 'central')
        )->getDatabaseName();

        if (DB::getDatabaseName() === $centralDbName) {
            // Throw if the current database is central.
            // DB::getDatabaseName() is the current DB name, which should not be central at this point.
            throw new RuntimeException('Tenant cannot use the central database.');
        }
    }
}

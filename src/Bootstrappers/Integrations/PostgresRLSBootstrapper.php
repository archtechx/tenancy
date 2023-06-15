<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

/**
 * Set Postgres credentials to the tenant's credentials and use Postgres as the central connection.
 *
 * This bootstrapper is intended to be used with Postgres RLS (single-database tenancy).
 *
 * @see \Stancl\Tenancy\Commands\CreatePostgresUserForTenants
 * @see \Stancl\Tenancy\Commands\CreateRLSPoliciesForTenantTables
 */
class PostgresRLSBootstrapper implements TenancyBootstrapper
{
    protected array $originalCentralConnectionConfig;
    protected array $originalPostgresConfig;

    public function __construct(
        protected Repository $config,
        protected DatabaseManager $database,
    ) {
        $this->originalCentralConnectionConfig = $config->get('database.connections.central');
        $this->originalPostgresConfig = $config->get('database.connections.pgsql');
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->database->purge($this->config->get('tenancy.database.central_connection'));

        /** @var TenantWithDatabase $tenant */
        $this->config->set('database.connections.pgsql.username', $tenant->database()->getUsername() ?? $tenant->getTenantKey());
        $this->config->set('database.connections.pgsql.password', $tenant->database()->getPassword() ?? 'password');

        $this->config->set(
            'database.connections.' . $this->config->get('tenancy.database.central_connection'),
            $this->config->get('database.connections.pgsql')
        );
    }

    public function revert(): void
    {
        $centralConnection = $this->config->get('tenancy.database.central_connection');

        $this->database->purge($centralConnection);

        $this->config->set('database.connections.' . $centralConnection, $this->originalCentralConnectionConfig);
        $this->config->set('database.connections.pgsql', $this->originalPostgresConfig);
    }
}
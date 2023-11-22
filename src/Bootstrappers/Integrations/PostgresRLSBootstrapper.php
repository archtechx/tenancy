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

    public function __construct(
        protected Repository $config,
        protected DatabaseManager $database,
    ) {
        $this->originalCentralConnectionConfig = $config->get('database.connections.central');
    }

    public function bootstrap(Tenant $tenant): void
    {
        $centralConnection = $this->config->get('tenancy.database.central_connection');
        $centralConnectionPassword = $this->config->get("database.connections.$centralConnection.password");

        $this->database->purge($centralConnection);

        /** @var TenantWithDatabase $tenant */
        $this->config->set([
            'database.connections.' . $centralConnection . '.username' => $tenant->database()->getUsername() ?? $tenant->getTenantKey(),
            'database.connections.' . $centralConnection . '.password' => $tenant->database()->getPassword() ?? $centralConnectionPassword,
        ]);
    }

    public function revert(): void
    {
        $centralConnection = $this->config->get('tenancy.database.central_connection');

        $this->database->purge($centralConnection);

        $this->config->set('database.connections.' . $centralConnection, $this->originalCentralConnectionConfig);
    }
}

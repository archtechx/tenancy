<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\DatabaseManager as TenantConnectionManager;

/**
 * When initializing tenancy, use the tenant connection with the configured RLS user credentials
 * and set the configured session variable to the current tenant's key.
 *
 * When ending tenancy, reset the session variable (to invalidate the connection)
 * and switch back to the central connection again.
 *
 * This bootstrapper is intended to be used with Postgres RLS.
 *
 * @see \Stancl\Tenancy\Commands\CreateUserWithRLSPolicies
 */
class PostgresRLSBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        protected Repository $config,
        protected DatabaseManager $database,
        protected TenantConnectionManager $tenantConnectionManager,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->connectToTenant();

        $tenantSessionKey = $this->config->get('tenancy.rls.session_variable_name');

        $this->database->statement("SET {$tenantSessionKey} = '{$tenant->getTenantKey()}'");
    }

    public function revert(): void
    {
        $this->database->statement("RESET {$this->config->get('tenancy.rls.session_variable_name')}");

        $this->tenantConnectionManager->reconnectToCentral();
    }

    protected function connectToTenant(): void
    {
        $centralConnection = $this->config->get('tenancy.database.central_connection');

        $this->tenantConnectionManager->purgeTenantConnection();

        $tenantConnection = array_merge($this->config->get('database.connections.' . $centralConnection), [
            'username' => $this->config->get('tenancy.rls.user.username'),
            'password' => $this->config->get('tenancy.rls.user.password'),
        ]);

        $this->config['database.connections.tenant'] = $tenantConnection;

        $this->tenantConnectionManager->setDefaultConnection('tenant');
    }
}

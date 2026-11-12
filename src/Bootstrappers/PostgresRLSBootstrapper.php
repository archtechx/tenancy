<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ConfigurationUrlParser;
use PDO;
use PDOException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager as TenantConnectionManager;

/**
 * When initializing tenancy, use the tenant connection with the configured RLS user credentials
 * and set the configured session variable to the current tenant's key. The variable is set on the
 * connection's write and read PDOs when they connect, since each PDO is a separate Postgres session.
 *
 * When ending tenancy, reset the session variable on the connected PDOs (to invalidate them)
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
        /** @var TenantWithDatabase $tenant */
        $this->connectToTenant($tenant);

        // set_config() is used instead of SET because SET doesn't support bindings
        $query = 'SELECT set_config(?, ?, false)';
        $bindings = [$this->config->get('tenancy.rls.session_variable_name'), $tenant->getTenantKey()];

        $connection = $this->database->connection('tenant');

        // Set the variable when each PDO connects. statement() would only set it on the write PDO
        // (connecting it right away), and with 'sticky', reads would go to the write PDO too
        $connection->setPdo($this->executeOnConnect($connection->getRawPdo(), $query, $bindings));
        $connection->setReadPdo($this->executeOnConnect($connection->getRawReadPdo(), $query, $bindings));
    }

    public function revert(): void
    {
        $connection = $this->database->connection('tenant');

        // Only reset the connected PDOs. statement() would only reach the write PDO, and it'd connect it if it isn't connected yet
        foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
            if ($pdo instanceof PDO) {
                try {
                    $pdo->exec("RESET {$this->config->get('tenancy.rls.session_variable_name')}");
                } catch (PDOException) {
                    // The PDOs get discarded below, so a failed reset (e.g. after a lost connection) shouldn't prevent ending tenancy
                }
            }
        }

        $this->tenantConnectionManager->reconnectToCentral();
    }

    protected function connectToTenant(TenantWithDatabase $tenant): void
    {
        $this->tenantConnectionManager->purgeTenantConnection();

        // Resolve the URL into individual keys, so that the URL's credentials don't take precedence over the RLS user's
        $centralConnection = (new ConfigurationUrlParser)->parseConfiguration(
            $this->config->get('database.connections.' . $this->config->get('tenancy.database.central_connection'))
        );

        $tenantConnection = array_merge($centralConnection, [
            'username' => $this->config->get('tenancy.rls.user.username'),
            'password' => $this->config->get('tenancy.rls.user.password'),
        ]);

        $this->config['database.connections.tenant'] = $tenant->database()->applyToSubConfigs($centralConnection, $tenantConnection);

        $this->tenantConnectionManager->setDefaultConnection('tenant');
    }

    /**
     * Execute the query on the PDO once it's connected.
     *
     * @param array<int, mixed> $bindings
     */
    protected function executeOnConnect(PDO|Closure|null $pdo, string $query, array $bindings): PDO|Closure|null
    {
        if ($pdo instanceof Closure) {
            return function () use ($pdo, $query, $bindings) {
                $pdo = $pdo();
                $pdo->prepare($query)->execute($bindings);

                return $pdo;
            };
        }

        $pdo?->prepare($query)->execute($bindings);

        return $pdo;
    }
}

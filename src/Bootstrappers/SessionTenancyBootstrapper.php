<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\SessionManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

// todo@rename this should be DB-specific

/**
 * This resets the database connection used by the database session driver.
 *
 * It runs each time tenancy is initialized or ended.
 * That way the session driver always uses the current DB connection.
 */
class SessionTenancyBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        protected Repository $config,
        protected Container $container,
        protected SessionManager $session,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        if ($this->config->get('session.driver') === 'database') {
            $this->resetDatabaseHandler();
        }
    }

    public function revert(): void
    {
        if ($this->config->get('session.driver') === 'database') {
            // When ending tenancy, this runs *before* the DatabaseTenancyBootstrapper, so DB tenancy
            // is still bootstrapped. For that reason, we have to explicitly use the central connection
            $this->resetDatabaseHandler(config('tenancy.database.central_connection'));
        }
    }

    protected function resetDatabaseHandler(string $defaultConnection = null): void
    {
        $sessionDrivers = $this->session->getDrivers();

        if (isset($sessionDrivers['database'])) {
            /** @var \Illuminate\Session\Store $databaseDriver */
            $databaseDriver = $sessionDrivers['database'];

            $databaseDriver->setHandler($this->createDatabaseHandler($defaultConnection));
        }
    }

    protected function createDatabaseHandler(string $defaultConnection = null): DatabaseSessionHandler
    {
        // Typically returns null, so this falls back to the default DB connection
        $connection = $this->config->get('session.connection') ?? $defaultConnection;

        // Based on SessionManager::createDatabaseDriver
        return new DatabaseSessionHandler(
            $this->container->make('db')->connection($connection),
            $this->config->get('session.table'),
            $this->config->get('session.lifetime'),
            $this->container,
        );
    }
}

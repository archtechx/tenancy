<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgresTenancyBootstrapper implements TenancyBootstrapper
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
        /** @var TenantWithDatabase $tenant */
        $this->database->purge('central');

        $this->config->set('database.connections.pgsql.username', $tenant->database()->getUsername() ?? $tenant->getTenantKey());
        $this->config->set('database.connections.pgsql.password', $tenant->database()->getPassword() ?? 'password');

        $this->config->set('database.connections.central', $this->config->get('database.connections.pgsql'));
    }

    public function revert(): void
    {
        $this->database->purge('central');

        $this->config->set('database.connections.central', $this->originalCentralConnectionConfig);
        $this->config->set('database.connections.pgsql', $this->originalPostgresConfig);
    }
}

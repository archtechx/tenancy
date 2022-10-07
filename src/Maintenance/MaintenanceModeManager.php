<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Maintenance;

use Illuminate\Support\Manager;

class MaintenanceModeManager extends Manager
{
    /**
     * Create an instance of the file based maintenance driver.
     *
     * @return DatabaseBasedMaintenanceMode
     */
    protected function createDatabaseDriver(): DatabaseBasedMaintenanceMode
    {
        return new DatabaseBasedMaintenanceMode();
    }

    /**
     * Create an instance of the cache based maintenance driver.
     *
     * @return CacheBasedMaintenanceMode
     *
     */
    protected function createCacheDriver(): CacheBasedMaintenanceMode
    {
        return new CacheBasedMaintenanceMode(
            $this->container->make('cache'),
            $this->config->get('tenancy.maintenance.store') ?: $this->config->get('cache.default'),
            tenant()->getTenantKey() . ':down'
        );
    }

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('tenancy.maintenance.driver', 'database');
    }
}

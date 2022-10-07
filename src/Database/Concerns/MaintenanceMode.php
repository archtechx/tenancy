<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Stancl\Tenancy\Maintenance\TenantMaintenanceModeContract;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait MaintenanceMode
{
    /**
     * Get an instance of the tenant maintenance mode manager implementation.
     *
     * @return TenantMaintenanceModeContract
     * @throws BindingResolutionException
     */
    public function maintenanceMode(): TenantMaintenanceModeContract
    {
        return app()->make(TenantMaintenanceModeContract::class);
    }

    /**
     * Put the tenant into maintenance
     *
     * @param  array  $payload
     * @return void
     * @throws BindingResolutionException
     */
    public function putDownForMaintenance(array $payload = []): void
    {
        ray('down');
        $this->maintenanceMode()->activate($payload);
    }

    /**
     * Remove the tenant from maintenance
     *
     * @return void
     * @throws BindingResolutionException
     */
    public function bringUpFromMaintenance(): void
    {
        $this->maintenanceMode()->deactivate();
    }

    /**
     * Determine if the tenant is in maintenance
     *
     * @return bool
     * @throws BindingResolutionException
     */
    public function isDownForMaintenance(): bool
    {
        return $this->maintenanceMode()->active();
    }

    /**
     * Get the data array which was provided when the tenant was placed into maintenance.
     *
     * @return array
     * @throws BindingResolutionException
     */
    public function getMaintenanceData(): array
    {
        return $this->maintenanceMode()->data();
    }
}

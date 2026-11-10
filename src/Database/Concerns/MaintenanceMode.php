<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Events\TenantMaintenanceModeDisabled;
use Stancl\Tenancy\Events\TenantMaintenanceModeEnabled;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait MaintenanceMode
{
    public function putDownForMaintenance($data = []): void
    {
        $this->update([
            'maintenance_mode' => [
                'except' => $data['except'] ?? null,
                'redirect' => $data['redirect'] ?? null,
                'render' => $data['render'] ?? null,
                'retry' => $data['retry'] ?? null,
                'refresh' => $data['refresh'] ?? null,
                'secret' => isset($data['secret']) ? (string) $data['secret'] : null,
                'status' => $data['status'] ?? 503,
            ],
        ]);

        event(new TenantMaintenanceModeEnabled($this));
    }

    public function bringUpFromMaintenance(): void
    {
        $this->update(['maintenance_mode' => null]);

        event(new TenantMaintenanceModeDisabled($this));
    }
}

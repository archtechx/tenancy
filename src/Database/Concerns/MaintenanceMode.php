<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Carbon\Carbon;
use Stancl\Tenancy\Events\TenantGoingInMaintenanceMode;
use Stancl\Tenancy\Events\TenantWentInMaintenanceMode;

trait MaintenanceMode
{
    public function putDownForMaintenance($data = [])
    {
        event(new TenantGoingInMaintenanceMode($this));

        $this->update(['maintenance_mode' => [
            'time' => $data['time'] ?? Carbon::now()->getTimestamp(),
            'message' => $data['message'] ?? null,
            'retry' => $data['retry'] ?? null,
            'allowed' => $data['allowed'] ?? [],
        ]]);

        event(new TenantWentInMaintenanceMode($this));
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

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
                'retry' => $data['retry'] ?? null,
                'refresh' => $data['refresh'] ?? null,
                'secret' => $data['secret'] ?? null,
                'status' => $data['status'] ?? 503,
            ],
        ]);
    }

    public function bringUpFromMaintenance(): void
    {
        $this->update(['maintenance_mode' => null]);
    }
}

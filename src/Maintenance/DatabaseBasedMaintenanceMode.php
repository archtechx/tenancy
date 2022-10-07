<?php

namespace Stancl\Tenancy\Maintenance;


class DatabaseBasedMaintenanceMode implements TenantMaintenanceModeContract
{

    public function activate(array $payload): void
    {
        tenant()->update([
            'maintenance_mode' => [
                'except' => $payload['except'] ?? null,
                'redirect' => $payload['redirect'] ?? null,
                'retry' => $payload['retry'] ?? null,
                'refresh' => $payload['refresh'] ?? null,
                'secret' => $payload['secret'] ?? null,
                'status' => $payload['status'] ?? 503,
            ],
        ]);
    }

    public function deactivate(): void
    {
        tenant()->update(['maintenance_mode' => null]);
    }

    public function active(): bool
    {
        return !is_null(tenant('maintenance_mode'));
    }

    public function data(): array
    {
        return tenant('maintenance_mode');
    }
}
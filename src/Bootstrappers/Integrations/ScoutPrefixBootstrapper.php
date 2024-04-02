<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class ScoutPrefixBootstrapper implements TenancyBootstrapper
{
    protected ?string $originalScoutPrefix = null;

    public function __construct(
        protected Repository $config,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        if ($this->originalScoutPrefix === null) {
            $this->originalScoutPrefix = $this->config->get('scout.prefix');
        }

        $this->config->set('scout.prefix', $this->getTenantPrefix($tenant));
    }

    public function revert(): void
    {
        $this->config->set('scout.prefix', $this->originalScoutPrefix);
    }

    protected function getTenantPrefix(Tenant $tenant): string
    {
        return (string) $tenant->getTenantKey();
    }
}

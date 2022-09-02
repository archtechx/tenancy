<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class ScoutTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var Repository */
    protected $config;

    /** @var string */
    protected $originalScoutPrefix;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function bootstrap(Tenant $tenant)
    {
        if (! isset($this->originalScoutPrefix)) {
            $this->originalScoutPrefix = $this->config->get('scout.prefix');
        }

        $this->config->set('scout.prefix', $tenant->getTenantKey());
    }

    public function revert()
    {
        $this->config->set('scout.prefix', $this->originalScoutPrefix);
    }
}

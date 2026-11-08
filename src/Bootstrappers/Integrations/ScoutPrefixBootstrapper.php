<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class ScoutPrefixBootstrapper implements TenancyBootstrapper
{
    /**
     * A closure that accepts the tenant and the original scout.prefix and returns the tenant's prefix.
     * When set, it replaces the default prefix format ({original prefix}{tenant key}_) entirely.
     *
     * @var Closure(Tenant, string): string
     */
    public static Closure|null $getTenantPrefixUsing = null;

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
        $originalPrefix = (string) $this->originalScoutPrefix;

        if (static::$getTenantPrefixUsing) {
            return (static::$getTenantPrefixUsing)($tenant, $originalPrefix);
        }

        // The _ separator prevents one tenant's prefix from being a prefix of another tenant's,
        // e.g. '1' and '10'. Scout's MeilisearchEngine::deleteAllIndexes() matches indexes by prefix.
        return $originalPrefix . $tenant->getTenantKey() . '_';
    }
}

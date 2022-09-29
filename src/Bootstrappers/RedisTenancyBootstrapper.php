<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class RedisTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var array<string, string> Original prefixes of connections */
    public $originalPrefixes = [];

    /** @var Repository */
    protected $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function bootstrap(Tenant $tenant): void
    {
        foreach ($this->prefixedConnections() as $connection) {
            $prefix = $this->config['tenancy.redis.prefix_base'] . $tenant->getTenantKey();
            $client = Redis::connection($connection)->client();

            /** @var string $originalPrefix */
            $originalPrefix = $client->getOption($client::OPT_PREFIX);

            $this->originalPrefixes[$connection] = $originalPrefix;
            $client->setOption($client::OPT_PREFIX, $prefix);
        }
    }

    public function revert(): void
    {
        foreach ($this->prefixedConnections() as $connection) {
            $client = Redis::connection($connection)->client();

            $client->setOption($client::OPT_PREFIX, $this->originalPrefixes[$connection]);
        }

        $this->originalPrefixes = [];
    }

    /** @return string[] */
    protected function prefixedConnections(): array
    {
        return $this->config['tenancy.redis.prefixed_connections'];
    }
}

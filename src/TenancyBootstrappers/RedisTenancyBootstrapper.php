<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class RedisTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var string[string] Original prefixes of connections */
    protected $originalPrefixes = [];

    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function start(Tenant $tenant)
    {
        foreach ($this->prefixedConnections() as $connection) {
            $prefix = $this->app['config']['tenancy.redis.prefix_base'] . $this->tenant['uuid'];
            $client = Redis::connection($connection)->client();

            $this->originalPrefixes[$connection] = $client->getOption($client::OPT_PREFIX);
            $client->setOption($client::OPT_PREFIX, $prefix);
        }
    }

    public function end()
    {
        foreach ($this->prefixedConnections() as $connection) {
            $client = Redis::connection($connection)->client();

            $client->setOption($client::OPT_PREFIX, $this->originalPrefixes[$connection]);
        }
    }

    protected function prefixedConnections()
    {
        return config('tenancy.redis.prefixed_connections');
    }
}

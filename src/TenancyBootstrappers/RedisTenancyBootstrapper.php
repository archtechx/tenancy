<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class RedisTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var array<string, string> Original prefixes of connections */
    public $originalPrefixes = [];

    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function start(Tenant $tenant)
    {
        foreach ($this->prefixedConnections() as $connection) {
            $prefix = $this->app['config']['tenancy.redis.prefix_base'] . $tenant['id'];
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

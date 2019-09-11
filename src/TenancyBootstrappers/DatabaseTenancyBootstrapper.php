<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBoostrappers;

use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Tenant;

class DatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var Application */
    protected $app;

    /** @var DatabaseManager */
    protected $database;

    public function __construct(Application $app, DatabaseManager $database)
    {
        $this->app = $app;
        $this->database = $database;
    }

    public function start(Tenant $tenant)
    {
        $this->database->connect($tenant);
    }

    public function end()
    {
        $this->database->reconnect();
    }
}

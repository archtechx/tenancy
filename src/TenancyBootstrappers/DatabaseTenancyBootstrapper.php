<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBoostrappers;

use Stancl\Tenancy\DatabaseManager;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

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
        $this->database->connect($tenant->getDatabaseName());
    }

    public function end()
    {
        $this->database->reconnect();
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Tenant;

class DatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var DatabaseManager */
    protected $database;

    public function __construct(DatabaseManager $database)
    {
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

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Foundation\Application;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\Contracts\TenancyEvent;

class UseCentralConnection
{
    public function __construct(
        protected DatabaseManager $database,
        protected Application $app
    ) {
    }

    public function handle(TenancyEvent $event): void
    {
        $this->database->purgeTenantConnection();
        $this->database->setDefaultConnection($this->app['config']->get('tenancy.database.central_connection'));
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\Contracts\TenancyEvent;

class CreateTenantConnection
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function handle(TenancyEvent $event): void
    {
        /** @var TenantWithDatabase $tenant */
        $tenant = $event->tenancy->tenant;

        $this->database->purgeTenantConnection();
        $this->database->createTenantConnection($tenant);
    }
}

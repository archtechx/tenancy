<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\Contracts\TenantEvent;

class CreateTenantConnection
{
    public function __construct(
        protected DatabaseManager $database,
    ) {
    }

    public function handle(TenantEvent $event): void
    {
        /** @var TenantWithDatabase */
        $tenant = $event->tenant;

        $this->database->createTenantConnection($tenant);
    }
}

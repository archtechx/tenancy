<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\Contracts\TenancyEvent;

class UseTenantConnection
{
    public function __construct(
        protected DatabaseManager $database,
    ) {
    }

    public function handle(TenancyEvent $event): void
    {
        $this->database->setDefaultConnection('tenant');
    }
}

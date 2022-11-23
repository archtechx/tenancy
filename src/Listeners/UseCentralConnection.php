<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\Contracts\TenancyEvent;

class UseCentralConnection
{
    public function __construct(
        protected DatabaseManager $database,
    ) {
    }

    public function handle(TenancyEvent $event): void
    {
        $this->database->reconnectToCentral();
    }
}

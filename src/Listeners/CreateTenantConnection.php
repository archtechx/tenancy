<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\Contracts\TenantEvent;

class CreateTenantConnection
{
    /** @var DatabaseManager */
    protected $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    public function handle(TenantEvent $event): void
    {
        $this->database->createTenantConnection($event->tenant);
    }
}

<?php

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

    public function handle(TenantEvent $event)
    {
        $this->database->createTenantConnection($event->tenant);
    }
}

<?php

namespace Stancl\Tenancy\StorageDrivers\Database;

use Stancl\Tenancy\DatabaseManager;

trait CentralConnection
{
    public function getConnectionName()
    {
        return app(DatabaseManager::class)->getCentralConnectionName();
    }
}
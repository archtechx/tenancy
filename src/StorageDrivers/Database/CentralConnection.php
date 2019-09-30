<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Stancl\Tenancy\DatabaseManager;

trait CentralConnection
{
    public function getConnectionName()
    {
        return app(DatabaseStorageDriver::class)->getCentralConnectionName();
    }
}

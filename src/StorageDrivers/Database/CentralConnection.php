<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

trait CentralConnection
{
    public function getConnectionName()
    {
        return DatabaseStorageDriver::getCentralConnectionName();
    }
}

<?php

namespace Stancl\Tenancy\Database\Concerns;

trait CentralConnection
{
    public function getConnectionName()
    {
        return config('tenancy.database.central_connection');
    }
}

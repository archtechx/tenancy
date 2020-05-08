<?php

namespace Stancl\Tenancy\Database\Models\Concerns;

trait CentralConnection
{
    public function getConnectionName()
    {
        return config('tenancy.central_connection');
    }
}

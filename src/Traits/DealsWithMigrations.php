<?php

namespace Stancl\Tenancy\Traits;

trait DealsWithMigrations
{
    protected function getMigrationPaths()
    {
        return [database_path('migrations/tenant')];
    }
}

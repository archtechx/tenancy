<?php

namespace Stancl\Tenancy\Traits;

trait DealsWithMigrations
{
    protected function getMigrationPaths()
    {
        return [config('tenancy.migrations_directory')];
    }
}

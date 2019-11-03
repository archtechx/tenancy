<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

trait DealsWithMigrations
{
    protected function getMigrationPaths()
    {
        if ($this->input->hasOption('path')) {
            return parent::getMigrationPaths();
        }

        return [config('tenancy.migrations_directory', database_path('migrations/tenant'))];
    }
}

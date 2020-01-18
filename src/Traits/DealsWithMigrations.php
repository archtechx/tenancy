<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

trait DealsWithMigrations
{
    protected function getMigrationPaths()
    {
        if ($this->input->hasOption('path') && $this->input->getOption('path')) {
            return parent::getMigrationPaths();
        }

        return config('tenancy.migrations_paths', [database_path('migrations/tenant')]);
    }
}

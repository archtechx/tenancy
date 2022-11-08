<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

/**
 * @mixin \Illuminate\Database\Console\Migrations\BaseCommand
 */
trait DealsWithMigrations
{
    protected function getMigrationPaths(): array
    {
        if ($this->input->hasOption('path') && $this->input->getOption('path')) {
            return parent::getMigrationPaths();
        }

        return [database_path('migrations/tenant')];
    }
}

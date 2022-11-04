<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;

class MigrateFreshOverride extends FreshCommand
{
    public function handle()
    {
        if (config('tenancy.database.drop_tenant_databases_on_migrate_fresh')) {
            tenancy()->model()::cursor()->each->delete();
        }

        return parent::handle();
    }
}

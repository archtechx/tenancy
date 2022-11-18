<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\Schema;

class MigrateFreshOverride extends FreshCommand
{
    public function handle()
    {
        if (config('tenancy.database.drop_tenant_databases_on_migrate_fresh')) {
            $tenantModel = tenancy()->model();

            if (Schema::hasTable($tenantModel->getTable())) {
                $tenantModel::cursor()->each->delete();
            }
        }

        return parent::handle();
    }
}

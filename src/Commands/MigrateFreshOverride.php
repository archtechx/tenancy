<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class MigrateFreshOverride extends FreshCommand
{
    public function handle()
    {
        tenancy()->model()::all()->each(function (TenantWithDatabase $tenant) {
            if (method_exists($tenant, 'domains')) {
                $tenant->domains()->delete();
            }

            $tenant->delete();
        });

        return parent::handle();
    }
}

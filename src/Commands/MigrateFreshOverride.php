<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;

class MigrateFreshOverride extends FreshCommand
{
    public function handle()
    {
        tenancy()->model()::all()->each->delete();

        return parent::handle();
    }
}

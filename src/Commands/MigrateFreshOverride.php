<?php

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;

class MigrateFreshOverride extends FreshCommand
{
    public function handle()
    {
        dd('overriden');
        tenancy()->model()::all()->each->delete();

        return parent::handle();
    }
}

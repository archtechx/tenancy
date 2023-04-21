<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;

class CreatePostgresUserForTenants extends Command
{
    use HasTenantOptions;

    protected $signature = 'tenants:create-postgres-user {--tenants=* : The tenant(s) to run the command for. Default: all}';

    public function handle(): int
    {
        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            CreatePostgresUserForTenant::dispatch();
        });

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\DumpCommand;
use Stancl\Tenancy\Contracts\Tenant;
use Symfony\Component\Console\Input\InputOption;

class TenantDump extends DumpCommand
{
    public function __construct()
    {
        parent::__construct();

        $this->setName('tenants:dump');
        $this->specifyParameters();
    }

    public function handle(ConnectionResolverInterface $connections, Dispatcher $dispatcher): int
    {
        $this->tenant()->run(fn () => parent::handle($connections, $dispatcher));

        return Command::SUCCESS;
    }

    public function tenant(): Tenant
    {
        $tenant = $this->option('tenant')
            ?? tenant()
            ?? $this->ask('What tenant do you want to dump the schema for?')
            ?? tenancy()->query()->first();

        if (! $tenant instanceof Tenant) {
            $tenant = tenancy()->find($tenant);
        }

        throw_if(! $tenant, 'Could not identify the tenant to use for dumping the schema.');

        return $tenant;
    }

    protected function getOptions(): array
    {
        return array_merge([
            ['tenant', null, InputOption::VALUE_OPTIONAL, '', null],
        ], parent::getOptions());
    }
}

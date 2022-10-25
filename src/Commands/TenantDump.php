<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Stancl\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\DumpCommand;
use Symfony\Component\Console\Input\InputOption;
use Illuminate\Database\ConnectionResolverInterface;

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
        if (is_null($this->option('path'))) {
            $this->input->setOption('path', config('tenancy.migration_parameters.--schema-path'));
        }

        $tenant = $this->option('tenant')
            ?? tenant()
            ?? $this->ask('What tenant do you want to dump the schema for?')
            ?? tenancy()->query()->first();

        if (! $tenant instanceof Tenant) {
            $tenant = tenancy()->find($tenant);
        }

        if ($tenant === null) {
            $this->components->error('Could not find tenant to use for dumping the schema.');

            return 1;
        }

        parent::handle($connections, $dispatcher);

        return 0;
    }

    protected function getOptions(): array
    {
        return array_merge([
            ['tenant', null, InputOption::VALUE_OPTIONAL, '', null],
        ], parent::getOptions());
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class Run extends Command
{
    use HasTenantOptions;

    protected $description = 'Run a command for tenant(s)';

    protected $signature = 'tenants:run {commandname : The artisan command.}
                            {--tenants=* : The tenant(s) to run the command for. Default: all}';

    public function handle(): int
    {
        /** @var string $commandName */
        $commandName = $this->argument('commandname');

        $stringInput = new StringInput($commandName);

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) use ($stringInput) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");

            $this->getLaravel()
                ->make(Kernel::class)
                ->handle($stringInput, new ConsoleOutput);
        });

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class Run extends Command
{
    use HasATenantsOption;

    protected $description = 'Run a command for tenant(s)';

    protected $signature = 'tenants:run {commandname : The artisan command.}
                            {--tenants=* : The tenant(s) to run the command for. Default: all}';

    public function handle(): int
    {
        $argvInput = $this->argvInput();

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) use ($argvInput) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");

            $this->getLaravel()
                ->make(Kernel::class)
                ->handle($argvInput, new ConsoleOutput);
        });

        return 0;
    }

    protected function argvInput(): ArgvInput
    {
        /** @var string $commandName */
        $commandName = $this->argument('commandname');

        // Convert string command to array
        $subCommand = explode(' ', $commandName);

        // Add "artisan" as first parameter because ArgvInput expects "artisan" as first parameter and later removes it
        array_unshift($subCommand, 'artisan');

        return new ArgvInput($subCommand);
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class Run extends Command
{
    protected $description = 'Run a command for tenant(s)';

    protected $signature = 'tenants:run {commandname : The artisan command.}
                            {--tenants=* : The tenant(s) to run the command for. Default: all}';

    public function handle(): void
    {
        $argvInput = $this->argvInput();
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) use ($argvInput) {
            $this->line("Tenant: {$tenant->getTenantKey()}");

            $this->getLaravel()
                ->make(Kernel::class)
                ->handle($argvInput, new ConsoleOutput);
        });
    }

    protected function argvInput(): ArgvInput
    {
        // Convert string command to array
        $subCommand = explode(' ', $this->argument('commandname'));

        // Add "artisan" as first parameter because ArgvInput expects "artisan" as first parameter and later removes it
        array_unshift($subCommand, 'artisan');

        return new ArgvInput($subCommand);
    }
}

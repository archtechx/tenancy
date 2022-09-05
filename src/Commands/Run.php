<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class Run extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a command for tenant(s)';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:run {commandname : The artisan command.}
                            {--tenants=* : The tenant(s) to run the command for. Default: all}';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $argvInput = $this->ArgvInput();
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) use ($argvInput) {
            $this->line("Tenant: {$tenant->getTenantKey()}");

            $this->getLaravel()
                ->make(Kernel::class)
                ->handle($argvInput, new ConsoleOutput);
        });
    }

    /**
     * Get command as ArgvInput instance.
     */
    protected function ArgvInput(): ArgvInput
    {
        // Convert string command to array
        $subCommand = explode(' ', $this->argument('commandname'));

        // Add "artisan" as first parameter because ArgvInput expects "artisan" as first parameter and later removes it
        array_unshift($subCommand, 'artisan');

        return new ArgvInput($subCommand);
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Console\Command;

class ExitCodeCommand extends Command
{
    protected $signature = 'exit-code {code}';

    public function handle(): int
    {
        $code = (int) $this->argument('code');

        $this->line("Exiting with code $code for tenant " . tenant()->getTenantKey());

        return $code;
    }
}

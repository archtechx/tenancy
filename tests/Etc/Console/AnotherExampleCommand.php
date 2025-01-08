<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Console\Command;

class AnotherExampleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bar {name} {email} {password} {arg} {--option=}';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->line('Name: ' . $this->argument('name'));
        $this->line('Email: ' . $this->argument('email'));
        $this->line('Password: ' . $this->argument('password'));
        $this->line('Argument: ' . $this->argument('arg'));
        $this->line('Option: ' . $this->option('option'));
    }
}

<?php

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Console\Command;

class ExampleQuestionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'age:ask {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $age = $this->ask('What is your age?');

        $this->line($this->argument('name') . "'s age is $age.");
    }
}

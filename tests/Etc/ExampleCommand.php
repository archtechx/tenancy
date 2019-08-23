<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Console\Command;

class ExampleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'foo {a} {--b=} {--c=}';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        User::create([
            'id' => 999,
            'name' => 'Test command',
            'email' => 'test@command.com',
            'password' => bcrypt('password'),
        ]);

        $this->line("User's name is " . User::find(999)->name);
        $this->line($this->argument('a'));
        $this->line($this->option('c'));
    }
}

class User extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
}

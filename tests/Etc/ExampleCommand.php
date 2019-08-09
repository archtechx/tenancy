<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Console\Command;

class ExampleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'foo {args*}';

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
        $this->line(implode(';', $this->argument('args')));
    }
}

class User extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
}

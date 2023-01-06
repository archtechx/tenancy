<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Support\Str;
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
        $id = User::create([
            'name' => 'Test user',
            'email' => Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
        ])->id;

        $this->line("User's name is " . User::find($id)->name);
        $this->line($this->argument('a'));
        $this->line($this->option('c'));
    }
}

class User extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
}

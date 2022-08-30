<?php

namespace Stancl\Tenancy\Tests\Etc\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tests\Etc\User;

class ExampleQuestionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:addwithname {name}';

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
        $email = $this->ask('What is your email?');

        User::create([
            'name' => $this->argument('name'),
            'email' => $email,
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
        ]);

        $this->line("User created: ". $this->argument('name') . "($email)");

        return 0;
    }
}

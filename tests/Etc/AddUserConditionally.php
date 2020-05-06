<?php

namespace Stancl\Tenancy\Tests\Etc;


use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Stancl\Tenancy\Traits\HasATenantsOption;
use Stancl\Tenancy\Traits\TenantAwareCommand;

class AddUserConditionally extends Command
{
    use TenantAwareCommand, HasATenantsOption;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:add_conditionally {--stop}';

    public function beforeRunningTenants()
    {
        if ($this->option('stop')) {
            $this->error('You stopped the command conditionally');
            return 1;
        }

        return 0;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        User::create([
            'name' => Str::random(10),
            'email' => Str::random(10) . '@gmail.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Console\Command;
use Stancl\Tenancy\Traits\TenantAwareCommand;

class AddUserCommand extends Command
{
    use TenantAwareCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:add';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        User::create();
    }
}

class User extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Orchestra\Testbench\Foundation\Console\Kernel;

class ConsoleKernel extends Kernel
{
    protected $commands = [
        ExampleCommand::class,
        AddUserCommand::class,
    ];
}

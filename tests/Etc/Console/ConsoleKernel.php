<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\Console;

use Orchestra\Testbench\Foundation\Console\Kernel;

class ConsoleKernel extends Kernel
{
    protected $commands = [
        ExampleCommand::class,
        ExampleQuestionCommand::class,
        AddUserCommand::class,
    ];
}

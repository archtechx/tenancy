<?php

namespace Stancl\Tenancy\Tests\Etc;

use Orchestra\Testbench\Console\Kernel;

class ConsoleKernel extends Kernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        ExampleCommand::class,
    ];
}

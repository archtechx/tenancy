<?php

use Stancl\Tenancy\Tests\TestCase;

uses(TestCase::class)->in(...filesAndFolder());

function pest(): \Orchestra\Testbench\TestCase
{
    return Pest\TestSuite::getInstance()->test;
}

function filesAndFolder(): array
{
    $dirs = scandir(__DIR__);

    return array_filter($dirs, fn($dir) => ! in_array($dir, ['.', '..', 'WithoutTenancy'], true));
}

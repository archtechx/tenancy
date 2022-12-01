<?php

use Stancl\Tenancy\Tests\TestCase;

uses(TestCase::class)->in(...filesAndFoldersExcluding(['WithoutTenancy'])); // todo move all tests to a separate folder

function pest(): TestCase
{
    return Pest\TestSuite::getInstance()->test;
}

function filesAndFoldersExcluding(array $exclude = []): array
{
    $dirs = scandir(__DIR__);

    return array_filter($dirs, fn($dir) => ! in_array($dir, array_merge(['.', '..'], $exclude) , true));
}

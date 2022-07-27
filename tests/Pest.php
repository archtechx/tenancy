<?php

use Stancl\Tenancy\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function pest(): TestCase
{
    return Pest\TestSuite::getInstance()->test;
}

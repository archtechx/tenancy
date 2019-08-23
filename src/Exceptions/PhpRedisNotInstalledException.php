<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

class PhpRedisNotInstalledException extends \Exception
{
    protected $message = 'PhpRedis is not installed. PhpRedis is required for Redis multi-tenancy because Predis does not support prefixes.';
}

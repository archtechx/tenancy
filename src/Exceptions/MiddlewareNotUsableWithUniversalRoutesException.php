<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Exception;

class MiddlewareNotUsableWithUniversalRoutesException extends Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: 'Universal routes are usable only with identification middleware that implements UsableWithUniversalRoutes.');
    }
}

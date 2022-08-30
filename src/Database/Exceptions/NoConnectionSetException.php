<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Exception;

class NoConnectionSetException extends Exception
{
    public function __construct(string $manager)
    {
        parent::__construct("No connection was set on this $manager instance.");
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\Exceptions;

use Exception;

class RLSCommentConstraintException extends Exception
{
    public function __construct(string|null $message = null)
    {
        parent::__construct($message ?? 'Invalid comment constraint.');
    }
}

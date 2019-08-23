<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

class CannotChangeUuidOrDomainException extends \Exception
{
    protected $message = 'Uuid and domain cannot be changed.';
}

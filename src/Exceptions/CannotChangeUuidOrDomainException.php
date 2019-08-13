<?php

namespace Stancl\Tenancy\Exceptions;

class CannotChangeUuidOrDomainException extends \Exception
{
    protected $message = 'Uuid and domain cannot be changed.';
}

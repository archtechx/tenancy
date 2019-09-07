<?php

namespace Stancl\Tenancy\Exceptions;

class NoTenantIdentifiedExceptions extends Exception
{
    protected $message = 'No tenant has been identified yet.';
}
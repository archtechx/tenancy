<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Events\Contracts;

use Stancl\Tenancy\Tenancy;

abstract class TenancyEvent
{
    public function __construct(
        public Tenancy $tenancy,
    ) {}
}

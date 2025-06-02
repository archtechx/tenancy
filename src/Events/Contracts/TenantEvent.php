<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Events\Contracts;

use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;

abstract class TenantEvent
{
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
    ) {}
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Events\Contracts;

use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;

abstract class TenantEvent // todo0 we could add a feature to JobPipeline that automatically gets data for the send() from here
{
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
    ) {}
}

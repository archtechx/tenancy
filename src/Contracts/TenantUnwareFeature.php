<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface TenantUnwareFeature
{
    public function bootstrap(): void;
}

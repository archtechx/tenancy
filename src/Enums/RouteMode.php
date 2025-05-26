<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Enums;

enum RouteMode: string
{
    case TENANT = 'tenant';
    case CENTRAL = 'central';
    case UNIVERSAL = 'universal';
}

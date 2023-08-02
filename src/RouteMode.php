<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

enum RouteMode
{
    case TENANT;
    case CENTRAL;
    case UNIVERSAL;
}

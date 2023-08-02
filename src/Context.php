<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

enum Context
{
    case TENANT;
    case CENTRAL;
}

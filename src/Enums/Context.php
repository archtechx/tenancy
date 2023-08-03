<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Enums;

enum Context
{
    case TENANT;
    case CENTRAL;
}

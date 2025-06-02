<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Enums;

/**
 * Note: The backing values are not part of the public API and are subject to change.
 */
enum RouteMode: int
{
    case CENTRAL   = 0b01;
    case TENANT    = 0b10;
    case UNIVERSAL = 0b11;
}

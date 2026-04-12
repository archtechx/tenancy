<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

/** Additional features, like Telescope tags and tenant redirects. */
interface Feature
{
    public function bootstrap(): void;
}

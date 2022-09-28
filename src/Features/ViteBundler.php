<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite as FoundationVite;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Vite as StanclVite;

class ViteBundler implements Feature
{
    public function __construct(protected Application $app) { }

    public function bootstrap(Tenancy $tenancy): void
    {
        $this->app->singleton(FoundationVite::class, StanclVite::class);
    }
}

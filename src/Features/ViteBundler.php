<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\App;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Vite;

class ViteBundler implements Feature
{
    public function bootstrap(Tenancy $tenancy): void
    {
        App::singleton(FoundationVite::class, Vite::class);
    }
}

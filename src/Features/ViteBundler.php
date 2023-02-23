<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Stancl\Tenancy\Vite;
use Stancl\Tenancy\Contracts\Feature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite as BaseVite;

class ViteBundler implements Feature
{
    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function bootstrap(): void
    {
        $this->app->singleton(BaseVite::class, Vite::class);
    }

    public static function alwaysBootstrap(): bool
    {
        return false;
    }
}

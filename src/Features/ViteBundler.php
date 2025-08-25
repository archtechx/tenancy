<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Vite;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenancy;

class ViteBundler implements Feature
{
    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function bootstrap(Tenancy $tenancy): void
    {
        Vite::createAssetPathsUsing(function ($path, $secure = null) {
            return global_asset($path);
        });
    }
}

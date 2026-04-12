<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Vite;
use Stancl\Tenancy\Contracts\Feature;

class ViteBundler implements Feature
{
    public function __construct(
        protected Application $app,
    ) {}

    public function bootstrap(): void
    {
        Vite::createAssetPathsUsing(function ($path, $secure = null) {
            return global_asset($path);
        });
    }
}

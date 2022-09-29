<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Closure;
use Illuminate\Routing\Controller;
use Throwable;

class TenantAssetsController extends Controller // todo rename this to TenantAssetController & update references in docs
{
    public static string|array|Closure $tenancyMiddleware = \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class;

    public function __construct()
    {
        $this->middleware(static::$tenancyMiddleware);
    }

    public function asset(string $path = null)
    {
        abort_if($path === null, 404);

        try {
            return response()->file(storage_path("app/public/$path"));
        } catch (Throwable) {
            abort(404);
        }
    }
}

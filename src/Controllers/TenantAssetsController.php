<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Illuminate\Routing\Controller;

class TenantAssetsController extends Controller
{
    public static $tenancyMiddleware = 'Stancl\Tenancy\Middleware\InitializeTenancyByDomain';

    public function __construct()
    {
        $this->middleware(static::$tenancyMiddleware);
    }

    public function asset($path)
    {
        try {
            return response()->file(storage_path("app/public/$path"));
        } catch (\Throwable $th) {
            abort(404);
        }
    }
}

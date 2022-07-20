<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Illuminate\Routing\Controller;
use Throwable;

class TenantAssetsController extends Controller
{
    public static $tenancyMiddleware = 'Stancl\Tenancy\Middleware\InitializeTenancyByDomain';

    public function __construct()
    {
        $this->middleware(static::$tenancyMiddleware);
    }

    public function asset($path = null)
    {
        abort_if($path === null, 404);

        try {
            return response()->file(storage_path("app/public/$path"));
        } catch (Throwable $th) {
            abort(404);
        }
    }
}

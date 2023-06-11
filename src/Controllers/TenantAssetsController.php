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
    
     public function assetWithPath($path = null)
    {
        abort_if($path === null, 404);

        /**
         * Prevents path traversal attack in asset requests
         *
         * @see https://www.stackhawk.com/blog/laravel-path-traversal-guide-examples-and-prevention/
         */
        $basePath = storage_path("app/public");
        $requestPath = realpath($basePath . '/' . $path);

        $validPath = $requestPath && substr($requestPath, 0, strlen($basePath)) === $basePath;
        abort_if($validPath === false, 404);

        try {
            return response()->file($requestPath);
        } catch (Throwable $th) {
            abort(404);
        }
    }
}

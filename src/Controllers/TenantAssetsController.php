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
        $this->validatePath($path);

        try {
            return response()->file(storage_path("app/public/$path"));
        } catch (Throwable $th) {
            abort(404);
        }
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function validatePath(string|null $path): void
    {
        abort_if($path === null, 404);

        $allowedRoot = storage_path('app/public');

        // Prevent path traversal attacks. This is generally a non-issue on modern
        // webservers but it's still worth handling on the application level as well.
        if (! str(realpath("{$allowedRoot}/{$path}"))->startsWith($allowedRoot)) {
            abort(403);
        }
    }
}

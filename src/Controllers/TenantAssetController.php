<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Illuminate\Routing\Controller;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TenantAssetController extends Controller // todo@docs this was renamed from TenantAssetsController
{
    public function __construct()
    {
        $this->middleware(Tenancy::defaultMiddleware());
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function asset(string $path = null): BinaryFileResponse
    {
        abort_if($path === null, 404);

        try {
            return response()->file(storage_path("app/public/$path"));
        } catch (Throwable) {
            abort(404);
        }
    }
}

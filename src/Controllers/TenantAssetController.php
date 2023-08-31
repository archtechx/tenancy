<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TenantAssetController extends Controller // todo@docs this was renamed from TenantAssetsController
{
    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function __invoke(string $path = null): BinaryFileResponse
    {
        abort_if($path === null, 404);

        try {
            return response()->file(storage_path("app/public/$path"));
        } catch (Throwable) {
            abort(404);
        }
    }
}

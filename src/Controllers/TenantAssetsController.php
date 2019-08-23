<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Illuminate\Routing\Controller;

class TenantAssetsController extends Controller
{
    public function __construct()
    {
        $this->middleware('tenancy');
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

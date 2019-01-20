<?php

namespace Stancl\Tenancy\Controllers;

use Illuminate\Routing\Controller;

class TenantAssetController extends Controller
{
    public function __construct()
    {
        $this->middleware('tenancy');
    }

    public function asset($path)
    {
        try {
            return response()->file(storage_path('app/public/' . $path));
        } catch (\Throwable $th) {
            dd(storage_path('app/public/' . $path));
            abort(404);
        }
    }
}

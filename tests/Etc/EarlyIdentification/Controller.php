<?php

namespace Stancl\Tenancy\Tests\Etc\EarlyIdentification;

use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    public function __construct(public Service $service)
    {
        app()->instance('controllerRunsInTenantContext', tenancy()->initialized);
        $this->middleware(AdditionalMiddleware::class);
    }

    public function index(): string
    {
        return $this->service->token;
    }
}

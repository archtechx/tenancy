<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\EarlyIdentification;

class Service
{
    public string $token;

    public function __construct()
    {
        $this->token = config('tenancy.test_service_token');
    }
}

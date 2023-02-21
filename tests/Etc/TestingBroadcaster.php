<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;

class TestingBroadcaster extends Broadcaster {
    public function __construct(
        public string $message
    ) {}

    public function auth($request)
    {
        return true;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return true;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
    }
}

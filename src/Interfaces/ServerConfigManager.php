<?php

namespace Stancl\Tenancy\Interfaces;

interface ServerConfigManager
{
    public function addVhost(string $domain, string $file): bool;
    public function deployCertificate(string $domain): bool;
}

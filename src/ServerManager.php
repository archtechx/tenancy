<?php

namespace Stancl\Tenancy;

use Stancl\Tenancy\Interfaces\ServerConfigManager;

class ServerManager
{
    public function __construct(ServerConfigManager $serverConfigManager, TenantManager $tenantManager)
    {
        $this->serverConfigManager = $serverConfigManager;
        $this->tenantManager = $tenantManager;
    }

    public function getConfigFilePath()
    {
        if (config('tenancy.server.file.single')) {
            return config('tenancy.server.file.path');
        }

        return config('tenancy.server.file.path.prefix') . $this->tenantManager->tenant['uuid'] . config('tenancy.server.file.path.suffix');
    }

    public function createVhost(string $domain)
    {
        // todo symlink
        $this->serverConfigManager->addVhost($domain, $this->getConfigFilePath());
        $this->serverConfigManager->deployCertificate($domain);
        if (method_exists($this->serverConfigManager, 'postCertDeploymentChanges')) {
            $this->serverConfigManager->postCertDeploymentChanges();
        }
    }

    public function deleteVhost()
    {
        // todo
    }
}

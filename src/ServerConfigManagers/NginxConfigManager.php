<?php

namespace Stancl\Tenancy\ServerConfigManagers;

use Symfony\Component\Process\Process;
use Stancl\Tenancy\Interfaces\ServerConfigManager;

class NginxConfigManager implements ServerConfigManager
{
    public function addVhost(string $domain, string $file): bool
    {
        $f = fopen($file, 'a');
        fwrite($f, $this->getVhostText($domain));
        fclose($f);
        return true;
    }

    public function getVhostText(string $domain)
    {
        return str_replace('%host%', $domain, config('tenancy.server.nginx.vhost'));
    }

    public function deployCertificate(string $domain): bool
    {
        $process = new Process(array_merge([
            config('tenancy.server.certbot_path'),
            '-n',
            '--nginx',
            '--agree-tos',
            '-d', $domain,
            '--preferred-challenges', 'http',
        ], config('tenancy.server.nginx.extra_certbot_args')));

        $process->run();

        return $process->isSuccessful();
    }
}

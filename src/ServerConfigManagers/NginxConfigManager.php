<?php

namespace Stancl\Tenancy\ServerConfigManagers;

use Symfony\Component\Process\Process;
use Stancl\Tenancy\Interfaces\ServerConfigManager;

class NginxConfigManager implements ServerConfigManager
{
    public function addVhost(string $domain, string $file): bool
    {
        $f = fopen($file, 'a');
        $fw = fwrite($f, $this->getVhostText($domain));
        $fc = fclose($f);
        return $fw && $fc;
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
            '--webroot',
            '-d', $domain,
            '--agree-tos',
            '--preferred-challenges', 'http',
            '--webroot-path', config('tenancy.server.nginx.webroot'),
        ], config('tenancy.server.nginx.extra_certbot_args')));

        $process->run();

        return $process->isSuccessful();
    }
}

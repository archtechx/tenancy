<?php

declare(strict_types=1);

use Stancl\Tenancy\Contracts\FeatureProvider;
use Stancl\Tenancy\TenantManager;

class TenantRedirect implements FeatureProvider
{
    public function bootstrap(TenantManager $tenantManager): void
    {
        RedirectResponse::macro('tenant', function (string $domain) {
            // replace first occurance of hostname fragment with $domain
            $url = $this->getTargetUrl();
            $hostname = parse_url($url, PHP_URL_HOST);
            $position = strpos($url, $hostname);
            $this->setTargetUrl(substr_replace($url, $domain, $position, strlen($hostname)));

            return $this;
        });
    }
}

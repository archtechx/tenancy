<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Http\RedirectResponse;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenancy;

class CrossDomainRedirect implements Feature
{
    public function bootstrap(Tenancy $tenancy): void
    {
        RedirectResponse::macro('domain', function (string $domain) {
            /** @var RedirectResponse $this */
            $url = $this->getTargetUrl();

            /**
             * The original hostname in the redirect response.
             *
             * @var string $hostname
             */
            $hostname = parse_url($url, PHP_URL_HOST);

            $this->setTargetUrl((string) str($url)->replace($hostname, $domain));

            return $this;
        });
    }
}

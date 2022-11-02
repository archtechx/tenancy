<?php

declare(strict_types=1);

namespace Stancl\Tenancy; // todo new Overrides namespace?

use Illuminate\Mail\MailManager;
use Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper;

/**
 * This class (paired with the MailTenancyBootstrapper)
 * allows tenants to have their custom mailer credentials.
 *
 * Tenancy swaps Laravel's MailManager singleton for an instance of this class,
 * which overrides the manager's get method to always resolve
 * the mailers specified in the static $tenantMailers property
 * instead of getting them from the $mailers property where they're cached.
 *
 * @see MailTenancyBootstrapper
 */
class TenancyMailManager extends MailManager
{
    /**
     * Mailers to always resolve from the container (even when they're
     * cached and available in the $mailers property).
     */
    public static array $tenantMailers = [];

    /**
     * Override the get method so that the mailers in $tenantMailers always get resolved,
     * even when they're cached and available in the $mailers property.
     */
    protected function get($name)
    {
        if (in_array($name, static::$tenantMailers)) {
            return $this->resolve($name);
        }

        return parent::get($name);
    }
}

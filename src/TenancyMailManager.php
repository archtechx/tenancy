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
 * the mailers specified in the static $mailersToAlwaysResolve property
 * instead of getting them from the $mailers property where they're cached.
 *
 * This is mainly used to solve the issue where
 * mail gets sent with the incorrect (old) mailer credentials
 * due to the mailer being cached in the manager's $mailers property
 * and not getting updated when the tenant's credentials change.
 *
 * @see MailTenancyBootstrapper
 */
class TenancyMailManager extends MailManager
{
    /**
     * Names of mailers which will always get re-resolved even when they're
     * cached & available in the $mailers property.
     */
    public static array $mailersToAlwaysResolve = [
        'smtp',
    ];

    /**
     * Override the get method so that the mailers in $mailersToAlwaysResolve
     * always get resolved even when they're cached and available in the $mailers property
     * for the mailers to have the up-to-date tenant credentials.
     */
    protected function get($name)
    {
        if (in_array($name, static::$mailersToAlwaysResolve)) {
            return $this->resolve($name);
        }

        return parent::get($name);
    }
}

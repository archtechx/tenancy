<?php

declare(strict_types=1);

namespace Stancl\Tenancy; // todo new Overrides namespace?

use Illuminate\Mail\MailManager;

class TenancyMailManager extends MailManager
{
    public static array $mailersToNotCache = [
        'smtp',
    ];

    protected function get($name)
    {
        if (in_array($name, static::$mailersToNotCache)) {
            return $this->resolve($name);
        }

        return parent::get($name);
    }
}

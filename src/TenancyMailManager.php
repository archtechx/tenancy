<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Mail\MailManager;

class TenancyMailManager extends MailManager
{
    protected function get($name)
    {
        return $this->resolve($name);
    }
}

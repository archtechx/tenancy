<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

use Symfony\Component\Console\Input\InputOption;

trait HasATenantOption
{
    protected function getOptions()
    {
        return array_merge([
            ['tenant', null, InputOption::VALUE_REQUIRED, '', null],
        ], parent::getOptions());
    }

    protected function getTenants(): array
    {
        return tenancy()->all($this->option('tenants'))->all();
    }
}

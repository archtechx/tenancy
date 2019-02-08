<?php

namespace Stancl\Tenancy\Traits;

trait HasATenantsOption
{
    protected function getOptions()
    {
        return array_merge([
            ['tenants', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, '', null]
        ], parent::getOptions());
    }
}

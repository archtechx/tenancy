<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

use Symfony\Component\Console\Input\InputOption;

trait HasATenantsOption
{
    protected function getOptions()
    {
        return array_merge([
            ['tenants', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, '', null],
        ], parent::getOptions());
    }

    protected function getTenants(): array
    {
        return tenancy()->all($this->option('tenants'))->all();
    }

    public function __construct()
    {
        parent::__construct();

        $this->specifyParameters();
    }
}

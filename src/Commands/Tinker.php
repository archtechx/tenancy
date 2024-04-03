<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Laravel\Tinker\Console\TinkerCommand as BaseTinker;
use Symfony\Component\Console\Input\InputArgument;

class Tinker extends BaseTinker
{
    public $name = 'tenant:tinker';

    protected function getArguments()
    {
        return array_merge([
            ['tenant', InputArgument::OPTIONAL, 'The tenant to run Tinker for. Pass the tenant key or leave null to default to the first tenant.'],
        ], parent::getArguments());
    }

    public function handle()
    {
        // ?: to support empty strings so that the original argument (`include`) can be reached even with a falsy tenant argument
        tenancy()->initialize($this->argument('tenant') ?: tenancy()->model()::first());

        parent::handle();
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use Laravel\Tinker\Console\TinkerCommand as BaseTinker;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
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
        /** @var string|int|null $tenantKey */
        $tenantKey = $this->argument('tenant');

        /** @var (\Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model)|null $firstTenant */
        $firstTenant = tenancy()->model()::first();

        /** @var string|int|null $firstTenantKey */
        $firstTenantKey = $firstTenant?->getTenantKey();

        $tenant = null;

        if (! $tenantKey) {
            $select = select('Which tenant do you want to run Tinker as?', [
                'first' => "First tenant ($firstTenantKey)",
                'searchById' => 'Search by id',
                'searchByDomain' => 'Search by domain',
            ]);

            if ($select === 'first') {
                $tenant = $firstTenant;
            } elseif ($select === 'searchById') {
                /** @var string $tenantKey */
                $tenantKey = search(
                    'Enter the tenant key:',
                    fn (string $search) => $search !== ''
                        ? tenancy()->model()::where(tenancy()->model()->getTenantKeyName(), 'like', "$search%")->pluck(tenancy()->model()->getTenantKeyName())->all()
                        : []
                );

                $tenant = tenancy()->find($tenantKey);
            } elseif ($select === 'searchByDomain') {
                /** @var string $domain */
                $domain = search(
                    'Enter the tenant domain:',
                    fn (string $search) => $search !== ''
                        ? config('tenancy.models.domain')::where('domain', 'like', "$search%")->pluck('domain')->all()
                        : []
                );

                $tenant = DomainTenantResolver::findTenantByDomain($domain);
            }
        } else {
            $tenant = tenancy()->find($tenantKey);
        }

        if (! $tenant) {
            $this->components->error('No tenant found.');

            return 1;
        }

        tenancy()->initialize($tenant);

        return parent::handle();
    }
}

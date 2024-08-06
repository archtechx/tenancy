<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Actions\CreateStorageSymlinksAction;
use Stancl\Tenancy\Actions\RemoveStorageSymlinksAction;
use Stancl\Tenancy\Concerns\HasTenantOptions;

class Link extends Command
{
    use HasTenantOptions;

    protected $signature = 'tenants:link
                {--tenants=* : The tenant(s) to run the command for. Default: all}
                {--relative : Create the symbolic link using relative paths}
                {--force : Recreate existing symbolic links}
                {--remove : Remove symbolic links}';

    protected $description = 'Create or remove tenant symbolic links.';

    public function handle(): int
    {
        $tenants = $this->getTenants();

        try {
            if ($this->option('remove')) {
                $this->removeLinks($tenants);
            } else {
                $this->createLinks($tenants);
            }
        } catch (Exception $exception) {
            $this->components->error($exception->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param LazyCollection<covariant int|string, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> $tenants
     */
    protected function removeLinks(LazyCollection $tenants): void
    {
        (new RemoveStorageSymlinksAction)($tenants);

        $this->components->info('The links have been removed.');
    }

    /**
     * @param LazyCollection<covariant int|string, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> $tenants
     */
    protected function createLinks(LazyCollection $tenants): void
    {
        (new CreateStorageSymlinksAction)(
            $tenants,
            (bool) ($this->option('relative') ?? false),
            (bool) ($this->option('force') ?? false),
        );

        $this->components->info('The links have been created.');
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Actions\CreateStorageSymlinksAction;
use Stancl\Tenancy\Actions\RemoveStorageSymlinksAction;
use Stancl\Tenancy\Concerns\HasATenantsOption;

class Link extends Command
{
    use HasATenantsOption;

    protected $signature = 'tenants:link
                {--tenants=* : The tenant(s) to run the command for. Default: all}
                {--relative : Create the symbolic link using relative paths}
                {--force : Recreate existing symbolic links}
                {--remove : Remove symbolic links}';

    protected $description = 'Create or remove tenant symbolic links.';

    public function handle(): void
    {
        $tenants = $this->getTenants();

        try {
            if ($this->option('remove')) {
                $this->removeLinks($tenants);
            } else {
                $this->createLinks($tenants);
            }
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    protected function removeLinks(LazyCollection $tenants): void
    {
        RemoveStorageSymlinksAction::handle($tenants);

        $this->components->info('The links have been removed.');
    }

    protected function createLinks(LazyCollection $tenants): void
    {
        CreateStorageSymlinksAction::handle(
            $tenants,
            (bool) ($this->option('relative') ?? false),
            (bool) ($this->option('force') ?? false),
        );

        $this->components->info('The links have been created.');
    }
}

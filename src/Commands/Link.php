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

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'tenants:link
                {--tenants=* : The tenant(s) to run the command for. Default: all}
                {--relative : Create the symbolic link using relative paths}
                {--force : Recreate existing symbolic links}
                {--remove : Remove symbolic links}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or remove tenant symbolic links.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
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

        $this->info('The links have been removed.');
    }

    protected function createLinks(LazyCollection $tenants): void
    {
        CreateStorageSymlinksAction::handle(
            $tenants,
            $this->option('relative') ?? false,
            $this->option('force') ?? false,
        );

        $this->info('The links have been created.');
    }
}

<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Console\Migrations\BaseCommand;
use Illuminate\Database\QueryException;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Concerns\ParallelCommand;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as OI;

class MigrateFresh extends BaseCommand
{
    use HasTenantOptions, DealsWithMigrations, ParallelCommand, ConfirmableTrait;

    protected $description = 'Drop all tables and re-run all migrations for tenant(s)';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('drop-views', null, InputOption::VALUE_NONE, 'Drop views along with tenant tables.', null);
        $this->addOption('step', null, InputOption::VALUE_NONE, 'Force the migrations to be run so they can be rolled back individually.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Force the command to run when in production.', null);
        $this->addProcessesOption();

        $this->setName('tenants:migrate-fresh');
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        $success = true;

        if ($this->getProcesses() > 1) {
            return $this->runConcurrently($this->getTenantChunks()->map(function ($chunk) {
                return $this->getTenants($chunk);
            }));
        }

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) use (&$success) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");
            $this->components->task('Dropping tables', function () use (&$success) {
                $success = $success && $this->wipeDB();
            });
            $this->components->task('Migrating', function () use ($tenant, &$success) {
                $success = $success && $this->migrateTenant($tenant);
            });
        });

        return $success ? 0 : 1;
    }

    protected function wipeDB(): bool
    {
        return $this->callSilently('db:wipe', array_filter([
            '--database' => 'tenant',
            '--drop-views' => $this->option('drop-views'),
            '--force' => true,
        ])) === 0;
    }

    protected function migrateTenant(TenantWithDatabase $tenant): bool
    {
        return $this->callSilently('tenants:migrate', [
            '--tenants' => [$tenant->getTenantKey()],
            '--step' => $this->option('step'),
            '--force' => true,
        ]) === 0;
    }

    protected function childHandle(mixed ...$args): bool
    {
        $chunk = $args[0];

        return $this->migrateFreshTenants($chunk);
    }

    /**
     * Only used when running concurrently.
     *
     * @param LazyCollection<covariant int|string, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> $tenants
     */
    protected function migrateFreshTenants(LazyCollection $tenants): bool
    {
        $success = true;

        foreach ($tenants as $tenant) {
            try {
                $this->components->info("Migrating (fresh) tenant {$tenant->getTenantKey()}");

                $tenant->run(function ($tenant) use (&$success) {
                    $this->components->info("Wiping database of tenant {$tenant->getTenantKey()}", OI::VERBOSITY_VERY_VERBOSE);
                    if ($this->wipeDB()) {
                        $this->components->info("Wiped database of tenant {$tenant->getTenantKey()}", OI::VERBOSITY_VERBOSE);
                    } else {
                        $success = false;
                        $this->components->error("Wiping database of tenant {$tenant->getTenantKey()} failed!");
                    }

                    $this->components->info("Migrating database of tenant {$tenant->getTenantKey()}", OI::VERBOSITY_VERY_VERBOSE);
                    if ($this->migrateTenant($tenant)) {
                        $this->components->info("Migrated database of tenant {$tenant->getTenantKey()}", OI::VERBOSITY_VERBOSE);
                    } else {
                        $success = false;
                        $this->components->error("Migrating database of tenant {$tenant->getTenantKey()} failed!");
                    }

                    $this->components->success("Migrated (fresh) tenant {$tenant->getTenantKey()}");
                });
            } catch (TenantDatabaseDoesNotExistException|QueryException $e) {
                $this->components->error("Migration failed for tenant {$tenant->getTenantKey()}: {$e->getMessage()}");
                $success = false;

                if (! $this->option('skip-failing')) {
                    throw $e;
                }
            }
        }

        return $success;
    }
}

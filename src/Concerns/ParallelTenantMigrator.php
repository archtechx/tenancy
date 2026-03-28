<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use RuntimeException;

/**
 * Parallel `tenants:migrate` via {@see Concurrency} and option forwarding for nested Artisan calls.
 */
trait ParallelTenantMigrator
{
    /**
     * Options for nested `tenants:migrate` (parallel workers). Omits `--parallel`.
     *
     * @return array<string, mixed>
     */
    protected function optionsForNestedMigrateCall(): array
    {
        $options = [];

        if ($database = $this->option('database')) {
            $options['--database'] = $database;
        }

        if ($this->option('force')) {
            $options['--force'] = true;
        }

        if ($paths = $this->option('path')) {
            $options['--path'] = $paths;
        }

        if ($this->option('realpath')) {
            $options['--realpath'] = true;
        }

        if ($schemaPath = $this->option('schema-path')) {
            $options['--schema-path'] = $schemaPath;
        }

        if ($this->option('pretend')) {
            $options['--pretend'] = true;
        }

        if ($this->option('seed')) {
            $options['--seed'] = true;
        }

        if ($seeder = $this->option('seeder')) {
            $options['--seeder'] = $seeder;
        }

        if ($this->option('step')) {
            $options['--step'] = true;
        }

        if ($this->option('graceful')) {
            $options['--graceful'] = true;
        }

        if ($this->option('no-interaction')) {
            $options['--no-interaction'] = true;
        }

        return $options;
    }

    /**
     * @param  list<string|int>  $keys
     * @param  callable(int $batchIndex, int $batchTotal, list<string|int> $batchKeys): void  $beforeBatch
     */
    protected function runParallelTenantBatches(array $keys, int $batchSize, callable $beforeBatch): void
    {
        $forward = $this->optionsForNestedMigrateCall();
        $batches = array_chunk($keys, max(1, $batchSize));
        $total = count($batches);

        foreach ($batches as $i => $batch) {
            $beforeBatch($i, $total, $batch);

            $tasks = [];
            foreach ($batch as $key) {
                $tasks[] = static fn () => self::migrateOneTenantViaArtisan($key, $forward);
            }

            Concurrency::run($tasks);
        }
    }

    /**
     * @param  array<string, mixed>  $forwardOptions
     */
    private static function migrateOneTenantViaArtisan(string|int $key, array $forwardOptions): void
    {
        $code = Artisan::call('tenants:migrate', array_merge($forwardOptions, [
            '--tenants' => [$key],
            '--force' => true,
        ]));

        if ($code !== 0) {
            throw new RuntimeException("Tenant migration failed for [{$key}] with exit code {$code}.");
        }
    }
}

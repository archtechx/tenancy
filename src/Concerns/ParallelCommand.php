<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use ArrayAccess;
use Countable;
use Exception;
use FFI;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputOption;

trait ParallelCommand
{
    public const int MAX_PROCESSES = 24;
    protected bool $runningConcurrently = false;

    abstract protected function childHandle(mixed ...$args): bool;

    public function addProcessesOption(): void
    {
        $this->addOption(
            'processes',
            'p',
            InputOption::VALUE_OPTIONAL,
            'How many processes to spawn. Maximum value: ' . static::MAX_PROCESSES . ', recommended value: core count (use just -p)',
            -1,
        );

        $this->addOption(
            'forceProcesses',
            'P',
            InputOption::VALUE_OPTIONAL,
            'Same as --processes but without a maximum value. Use at your own risk',
            -1,
        );
    }

    protected function forkProcess(mixed ...$args): int
    {
        if (! app()->runningInConsole()) {
            throw new Exception('Parallel commands are only available in CLI context.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return -1;
        } elseif ($pid) {
            // Parent
            return $pid;
        } else {
            // Child
            DB::reconnect();

            exit($this->childHandle(...$args) ? 0 : 1);
        }
    }

    protected function sysctlGetLogicalCoreCount(bool $darwin): int
    {
        $ffi = FFI::cdef('int sysctlbyname(const char *name, void *oldp, size_t *oldlenp, void *newp, size_t newlen);');

        $cores = $ffi->new('int');
        $size = $ffi->new('size_t');
        $size->cdata = FFI::sizeof($cores);

        // perflevel0 refers to P-cores on M-series, and the entire CPU on Intel Macs
        if ($darwin && $ffi->sysctlbyname('hw.perflevel0.logicalcpu', FFI::addr($cores), FFI::addr($size), null, 0) === 0) {
            return $cores->cdata;
        } elseif ($darwin) {
            // Reset the size in case the pointer got written to (likely shouldn't happen)
            $size->cdata = FFI::sizeof($cores);
        }

        // This should return the total number of logical cores on any BSD-based system
        if ($ffi->sysctlbyname('hw.ncpu', FFI::addr($cores), FFI::addr($size), null, 0) !== 0) {
            return -1;
        }

        return $cores->cdata;
    }

    protected function getLogicalCoreCount(): int
    {
        // We use the logical core count as it should work best for I/O bound code
        return match (PHP_OS_FAMILY) {
            'Windows' => (int) getenv('NUMBER_OF_PROCESSORS'),
            'Linux' => substr_count(
                file_get_contents('/proc/cpuinfo') ?: throw new Exception('Could not open /proc/cpuinfo for core count detection, please specify -p manually.'),
                'processor',
            ),
            'Darwin', 'BSD' => $this->sysctlGetLogicalCoreCount(PHP_OS_FAMILY === 'Darwin'),
            default => throw new Exception('Core count detection not implemented for ' . PHP_OS_FAMILY . ', please specify -p manually.'),
        };
    }

    protected function getProcesses(): int
    {
        $processes = $this->input->getOption('forceProcesses');
        $forceProcesses = $processes !== -1;

        if ($processes === -1) {
            $processes = $this->input->getOption('processes');
        }

        if ($processes === null) {
            // This is used when the option is set but *without* a value (-p).
            $processes = $this->getLogicalCoreCount();
        } elseif ((int) $processes === -1) {
            // Default value we set for the option -- this is used when the option is *not set*.
            $processes = 1;
        } else {
            // Option value set by the user.
            $processes = (int) $processes;
        }

        if ($processes < 1) {
            $this->components->error('Minimum value for processes is 1. Try specifying -p manually.');
            exit(1);
        }

        if ($processes > static::MAX_PROCESSES && ! $forceProcesses) {
            $this->components->error('Maximum value for processes is ' . static::MAX_PROCESSES . ' provided value: ' . $processes);
            exit(1);
        }

        if ($processes > 1 && ! function_exists('pcntl_fork')) {
            $this->components->error('The pcntl extension is required for parallel migrations to work.');
            exit(1);
        }

        return $processes;
    }

    /**
     * @return Collection<int, array<int, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model>>
     */
    protected function getTenantChunks(): Collection
    {
        $idCol = tenancy()->model()->getTenantKeyName();
        $tenants = tenancy()->model()->orderBy($idCol, 'asc')->pluck($idCol);

        return $tenants->chunk((int) ceil($tenants->count() / $this->getProcesses()))->map(function ($chunk) {
            $chunk = array_values($chunk->all());

            /** @var array<int, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> $chunk */
            return $chunk;
        });
    }

    /**
     * @param array|(ArrayAccess<int, mixed>&Countable)|null $args
     */
    protected function runConcurrently(array|(ArrayAccess&Countable)|null $args = null): int
    {
        $processes = $this->getProcesses();
        $success = true;
        $pids = [];

        if ($args !== null && count($args) < $processes) {
            $processes = count($args);
        }

        $this->runningConcurrently = true;

        for ($i = 0; $i < $processes; $i++) {
            $pid = $this->forkProcess($args !== null ? $args[$i] : null);

            if ($pid === -1) {
                $this->components->error("Unable to fork process (iteration $i)!");
                if ($i === 0) {
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        // Fork equivalent of joining an array of join handles
        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);

            $normalExit = pcntl_wifexited($status);

            if ($normalExit) {
                $exitCode = pcntl_wexitstatus($status);

                if ($exitCode === 0) {
                    $this->components->success("Child process [$i] (PID $pid) finished successfully.");
                } else {
                    $success = false;
                    $this->components->error("Child process [$i] (PID $pid) completed with failures.");
                }
            } else {
                $success = false;
                $this->components->error("Child process [$i] (PID $pid) exited abnormally.");
            }
        }

        return $success ? 0 : 1;
    }
}

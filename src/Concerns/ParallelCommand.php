<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use ArrayAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputOption;

trait ParallelCommand
{
    public const MAX_PROCESSES = 24;

    abstract protected function childHandle(...$args): bool;

    public function addProcessesOption(): void
    {
        $this->addOption('processes', 'p', InputOption::VALUE_OPTIONAL, 'How many processes to spawn. Maximum value: ' . static::MAX_PROCESSES . ', recommended value: core count', 1);
    }

    protected function forkProcess(...$args): int
    {
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

    protected function getProcesses(): int
    {
        $processes = (int) $this->input->getOption('processes');

        if (($processes < 0) || ($processes > static::MAX_PROCESSES)) {
            $this->components->error('Maximum value for processes is ' . static::MAX_PROCESSES);
            exit(1);
        }

        if ($processes > 1 && ! function_exists('pcntl_fork')) {
            $this->components->error('The pcntl extension is required for parallel migrations to work.');
        }

        return $processes;
    }

    protected function getTenantChunks(): Collection
    {
        $idCol = tenancy()->model()->getTenantKeyName();
        $tenants = tenancy()->model()->orderBy($idCol, 'asc')->pluck($idCol);

        return $tenants->chunk(ceil($tenants->count() / $this->getProcesses()));
    }

    protected function runConcurrently(array|ArrayAccess|null $args = null): int
    {
        $processes = $this->getProcesses();
        $success = true;
        $pids = [];

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
                    $this->components->info("Child process [$i] (PID $pid) finished successfully.");
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

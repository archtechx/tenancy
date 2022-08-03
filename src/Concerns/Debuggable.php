<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Closure;
use Stancl\Tenancy\Enums\LogMode;
use Stancl\Tenancy\Events\Contracts\TenancyEvent;
use Stancl\Tenancy\Tenancy;

/**
 * @mixin Tenancy
 */
trait Debuggable
{
    protected LogMode $logMode = LogMode::NONE;
    protected array $eventLog = [];

    public function log(LogMode $mode = LogMode::SILENT): static
    {
        $this->eventLog = [];
        $this->logMode = $mode;

        return $this;
    }

    public function logMode(): LogMode
    {
        return $this->logMode;
    }

    public function getLog(): array
    {
        return $this->eventLog;
    }

    public function logEvent(TenancyEvent $event): static
    {
        $this->eventLog[] = ['time' => now(), 'event' => $event::class, 'tenant' => $this->tenant];

        return $this;
    }

    public function dump(Closure $dump = null): static
    {
        $dump ??= dd(...);

        // Dump the log if we were already logging in silent mode
        // Otherwise start logging in instant mode
        match ($this->logMode) {
            LogMode::NONE => $this->log(LogMode::INSTANT),
            LogMode::SILENT => $dump($this->eventLog),
            LogMode::INSTANT => null,
        };

        return $this;
    }

    public function dd(Closure $dump = null): void
    {
        $dump ??= dd(...);

        if ($this->logMode === LogMode::SILENT) {
            $dump($this->eventLog);
        } else {
            $dump($this);
        }
    }
}

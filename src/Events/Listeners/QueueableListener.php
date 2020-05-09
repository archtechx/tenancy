<?php

namespace Stancl\Tenancy\Events\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class QueueableListener implements ShouldQueue
{
    public static $shouldQueue = false;

    abstract public function handle();

    public function shouldQueue($event)
    {
        if (static::$shouldQueue) {
            return true;
        } else {
            // The listener is not queued so we manually
            // pass the event to the handle() method.
            $this->handle($event);

            return false;
        }
    }
}

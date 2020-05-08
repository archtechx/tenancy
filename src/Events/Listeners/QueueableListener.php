<?php

namespace Stancl\Tenancy\Events\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class QueueableListener implements ShouldQueue
{
    public static $shouldQueue = false;

    public function shouldQueue()
    {
        return static::$shouldQueue;
    }
}

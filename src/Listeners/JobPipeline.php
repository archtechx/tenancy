<?php

namespace Stancl\Tenancy\Listeners;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;

class JobPipeline implements ShouldQueue
{
    /** @var bool */
    public static $queueByDefault = false;

    /** @var callable[]|string[] */
    public $jobs;

    /** @var callable|null */
    public $send;

    /**
     * A value passed to the jobs. This is the return value of $send.
     */
    public $passable;

    /** @var bool */
    public $queue;

    public function __construct($jobs, callable $send = null, bool $queue = null)
    {
        $this->jobs = $jobs;
        $this->send = $send ?? function ($event) {
            // If no $send callback is set, we'll just pass the event through the jobs.
            return $event;
        };
        $this->queue = $queue ?? static::$queueByDefault;
    }

    /** @param callable[]|string[] $jobs */
    public static function make(array $jobs): self
    {
        return new static($jobs);
    }

    public function send(callable $send): self
    {
        $this->send = $send;

        return $this;
    }

    public function queue(bool $queue)
    {
        $this->queue = $queue;

        return $this;
    }

    public function handle(): void
    {
        foreach ($this->jobs as $job) {
            app()->call([new $job(...$this->passable), 'handle']);
        }
    }

    /**
     * Generate a closure that can be used as a listener.
     */
    public function toListener(): Closure
    {
        return function (...$args) {
            $executable = $this->executable($args);

            if ($this->queue) {
                dispatch($executable);
            } else {
                dispatch_now($executable);
            }
        };
    }

    /**
     * Return a serializable version of the current object.
     */
    public function executable($listenerArgs): self
    {
        $clone = clone $this;

        $passable = ($clone->send)(...$listenerArgs);
        $passable = is_array($passable) ? $passable : [$passable];

        $clone->passable = $passable;
        unset($clone->send);

        return $clone;
    }
}

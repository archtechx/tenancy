<?php

namespace Stancl\Tenancy\Events\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Pipeline\Pipeline;

class JobPipeline implements ShouldQueue
{
    /** @var bool */
    public static $shouldQueueByDefault = true;

    /** @var callable[]|string[] */
    public $jobs = [];
    
    /** @var callable */
    public $send;
    
    /** @var bool */
    public $shouldQueue = true;

    public function __construct($jobs, callable $send = null, bool $shouldQueue = null)
    {
        $this->jobs = $jobs;
        $this->send = $send ?? function ($event) {
            // If no $send callback is set, we'll just pass the event through the jobs.
            return $event;
        };
        $this->shouldQueue = $shouldQueue ?? static::$shouldQueueByDefault;
    }

    /** @param callable[]|string[] $jobs */
    public static function make(array $jobs): self
    {
        return new static($jobs);
    }

    public function queue(bool $shouldQueue): self
    {
        $this->shouldQueue = $shouldQueue;

        return $this;
    }

    public function send(callable $send): self
    {
        $this->send = $send;

        return $this;
    }

    /** @return bool|$this */
    public function shouldQueue(bool $shouldQueue = null)
    {
        if ($shouldQueue !== null) {
            $this->shouldQueue = $shouldQueue;

            return $this;
        }

        return $this->shouldQueue;
    }

    public function handle($event): void
    {
        /** @var Pipeline $pipeline */
        $pipeline = app(Pipeline::class);

        $pipeline
            ->send(($this->send)($event))
            ->through($this->jobs)
            ->thenReturn();
    }
}

<?php

namespace Stancl\Tenancy\Traits;

trait TenantManagerEvents
{
    /**
     * Event listeners
     *
     * @var callable[][]
     */
    protected $listeners = [
        'bootstrapping' => [],
        'bootstrapped' => [],
        'ending' => [],
        'ended' => [],
    ];

    /**
     * Register a listener that will be executed before tenancy is bootstrapped.
     *
     * @param callable $callback
     * @return self
     */
    public function bootstrapping(callable $callback)
    {
        $this->listeners['bootstrapping'][] = $callback;

        return $this;
    }

    /**
     * Register a listener that will be executed after tenancy is bootstrapped.
     *
     * @param callable $callback
     * @return self
     */
    public function bootstrapped(callable $callback)
    {
        $this->listeners['bootstrapped'][] = $callback;

        return $this;
    }

    /**
     * Register a listener that will be executed before tenancy is ended.
     *
     * @param callable $callback
     * @return self
     */
    public function ending(callable $callback)
    {
        $this->listeners['ending'][] = $callback;

        return $this;
    }

    /**
     * Register a listener that will be executed after tenancy is ended.
     *
     * @param callable $callback
     * @return self
     */
    public function ended(callable $callback)
    {
        $this->listeners['ended'][] = $callback;

        return $this;
    }
}
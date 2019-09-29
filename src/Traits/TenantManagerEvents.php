<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

use Illuminate\Support\Collection;

trait TenantManagerEvents
{
    /**
     * Event listeners.
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
     * Integration listeners.
     *
     * @var callable[][]
     */
    protected $integrationListeners = [];

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

    /**
     * Fire an event.
     *
     * @param string $name Event name
     * @return Collection Prevented events
     */
    public function event(string $name): Collection
    {
        return \array_reduce($this->listeners[$name], function ($prevents, $listener) {
            return $prevents->merge($listener($this) ?? []);
        }, collect([]));
    }

    /**
     * Register a callback for an integration event.
     *
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function integrationEvent(string $name, callable $callback)
    {
        if (\array_key_exists($name, $this->integrationListeners)) {
            $this->integrationListeners[$name][] = $callback;
        } else {
            $this->integrationListeners[$name] = [$callback];
        }
    }

    /**
     * Return callbacks for an integration event.
     *
     * @param string $name
     * @param mixed $arguments,...
     * @return callable[]
     */
    public function integration(string $name, ...$arguments)
    {
        if ($arguments) {
            // If $arguments are supplied, execute all listeners with arguments.
            return \array_reduce($this->integrationListeners[$name] ?? [], function ($tags, $listener) use ($arguments) {
                return \array_merge($tags, $listener(...$arguments));
            }, []);
        }

        return $this->integrationListeners[$name];
    }
}

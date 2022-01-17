<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Carbon\Carbon;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * @property null|Carbon $readied
 *
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withReadied(bool $withReadied = true)
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder onlyReadied()
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withoutReadied()
 */
trait WithReadied
{
    /**
     * Boot the readied trait for a model.
     *
     * @return void
     */
    public static function bootWithReadied()
    {
        static::addGlobalScope(new ReadiedScope());
    }

    /**
     * Initialize the readied trait for an instance.
     *
     * @return void
     */
    public function initializeSoftDeletes()
    {
        $this->casts['readied'] = 'datetime';
    }


    /**
     * Determine if the model instance is in a readied state.
     *
     * @return bool
     */
    public function readied()
    {
        return !is_null($this->readied);
    }

    public static function createReadied($attributes = []): void
    {
        $tenant = static::create($attributes);

        // We add the readied value only after the model has then been created.
        // this ensures the model is not marked as readied until the migrations, seeders, etc. are done
        $tenant->update([
            'readied' => now()->timestamp
        ]);
    }

    public static function pullReadiedTenant(bool $firstOrCreate = false): ?Tenant
    {
        if (!static::onlyReadied()->exists()) {
            if (!$firstOrCreate) {
                return null;
            }
            static::createReadied();
        }

        // At this point we can guarantee a readied tenant is free and can be called
        $tenant = static::onlyReadied()->first();

        $tenant->update([
            'readied' => null
        ]);

        return $tenant;
    }
}

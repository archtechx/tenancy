<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use BadMethodCallException;
use Illuminate\Cache\MemoizedStore;

/**
 * Memoized store wrapping a tenant-tagged repository.
 *
 * Tags passed to tags() are merged with the tenant tags, so
 * tagged memoized entries stay separated between tenants.
 *
 * @property \Illuminate\Cache\TaggedCache $repository
 */
class TenantMemoizedStore extends MemoizedStore
{
    /**
     * @param array|mixed $names
     * @return \Illuminate\Cache\TaggedCache
     */
    public function tags($names)
    {
        // MemoizedStore::tags() was added in Laravel 13
        if (! method_exists(MemoizedStore::class, 'tags')) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        $names = is_array($names) ? $names : func_get_args();

        return parent::tags(array_merge($this->repository->getTags()->getNames(), $names));
    }
}

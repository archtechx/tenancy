<?php

namespace Stancl\Tenancy\Repository;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;

class IlluminateTenantRepository implements TenantRepository
{
    public function __construct(
        /** @var class-string<Model & Tenant> */
        public readonly string $modelClass,
    ) {
    }

    public function find(int|string $id): ?Tenant
    {
        return $this->query()->find($id);
    }

    public function findForDomain(string $domain): ?Tenant
    {
        return $this->query()
            ->whereRelation('domains', 'domain', $domain)
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function all(): iterable
    {
        return $this->query()->cursor();
    }

    /**
     * @inheritDoc
     */
    public function whereKeyIn(string|int ...$ids): iterable
    {
        return $this->query()->whereKey($ids)->cursor();
    }

    /**
     * @return Builder<Model & Tenant>
     */
    private function query(): Builder
    {
        return (new $this->modelClass)::query();
    }
}

<?php

namespace Stancl\Tenancy\Testing;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TenantAwareTestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Set this to true in child classes to enable tenancy.
     *
     * @var bool
     */
    protected bool $tenancy = true;

    /**
     * Tenant instance used in the test.
     *
     * @var \App\Models\Tenant|null
     */
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->tenancy) {
            $this->initializeTenancy();
        }
    }

    /**
     * Initialize tenancy for a specific tenant.
     *
     * @param string|int|null $tenantIdentifier
     * @return void
     */
    protected function initializeTenancy(string|int|null $tenantIdentifier = null): void
    {
        $identifier = $tenantIdentifier ?? config('tenancy.test_tenant');

        $this->tenant = Tenant::find($identifier);

        if (! $this->tenant) {
            $this->fail("Tenant [{$identifier}] not found. Please ensure TEST_TENANT is set correctly in .env.testing or config('tenancy.test_tenant').");
        }

        tenancy()->initialize($this->tenant);
    }

    /**
     * Run assertions or actions in central context.
     */
    protected function runInCentralContext(callable $callback): mixed
    {
        $tenant = $this->tenant ?? tenancy()->tenant;

        tenancy()->end();

        try {
            return $callback();
        } finally {
            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }
    }
}

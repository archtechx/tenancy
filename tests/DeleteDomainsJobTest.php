<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Jobs\DeleteDomains;

class DeleteDomainsJobTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.tenant_model' => DatabaseAndDomainTenant::class]);
    }

    /** @test */
    public function job_delete_domains_successfully()
    {
        $tenant = DatabaseAndDomainTenant::create();

        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);
        $tenant->domains()->create([
            'domain' => 'bar.localhost',
        ]);

        $this->assertSame($tenant->domains()->count(), 2);

        (new DeleteDomains($tenant))->handle();

        $this->assertSame($tenant->refresh()->domains()->count(), 0);
    }
}

class DatabaseAndDomainTenant extends Etc\Tenant
{
    use HasDomains;
}

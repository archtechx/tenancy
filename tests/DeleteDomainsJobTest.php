<?php

declare(strict_types=1);

use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Jobs\DeleteDomains;

beforeEach(function () {
    config(['tenancy.models.tenant' => DatabaseAndDomainTenant::class]);
});

test('job deletes domains successfully', function () {
    $tenant = DatabaseAndDomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);
    $tenant->domains()->create([
        'domain' => 'bar.localhost',
    ]);

    expect($tenant->domains()->count())->toBe(2);

    (new DeleteDomains($tenant))->handle();

    expect($tenant->refresh()->domains()->count())->toBe(0);
});

class DatabaseAndDomainTenant extends \Stancl\Tenancy\Tests\Etc\Tenant
{
    use HasDomains;
}

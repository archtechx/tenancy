<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Jobs\DeleteDomains;

beforeEach(function () {
    config(['tenancy.models.tenant' => Tenant::class]);
});

test('job deletes domains successfully', function () {
    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);
    $tenant->domains()->create([
        'domain' => 'bar.localhost',
    ]);

    expect($tenant->domains()->count())->toBe(2);

    (new DeleteDomains($tenant->refresh()))->handle();

    expect($tenant->refresh()->domains()->count())->toBe(0);
});

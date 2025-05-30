<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config([
        'tenancy.identification.central_domains' => [
            'localhost',
        ],
    ]);

    Route::middleware([InitializeTenancyByRequestData::class])->get('/test', function () {
        return 'Tenant id: ' . tenant('id');
    });
});

test('header identification works', function () {
    $tenant = Tenant::create();

    // Default header name
    $this->withoutExceptionHandling()->withHeader('X-Tenant', $tenant->id)->get('test')->assertSee($tenant->id);

    // Custom header name
    config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.header' => 'X-Custom-Tenant']);
    $this->withoutExceptionHandling()->withHeader('X-Custom-Tenant', $tenant->id)->get('test')->assertSee($tenant->id);

    // Setting the header to null disables header identification
    config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.header' => null]);
    expect(fn () => $this->withoutExceptionHandling()->withHeader('X-Tenant', $tenant->id)->get('test'))->toThrow(TenantCouldNotBeIdentifiedByRequestDataException::class);
});

test('query parameter identification works', function () {
    $tenant = Tenant::create();

    // Default query parameter name
    $this->withoutExceptionHandling()->get('test?tenant=' . $tenant->id)->assertSee($tenant->id);

    // Custom query parameter name
    config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.query_parameter' => 'custom_tenant']);
    $this->withoutExceptionHandling()->get('test?custom_tenant=' . $tenant->id)->assertSee($tenant->id);

    // Setting the query parameter to null disables query parameter identification
    config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.query_parameter' => null]);
    expect(fn () => $this->withoutExceptionHandling()->get('test?tenant=' . $tenant->id))->toThrow(TenantCouldNotBeIdentifiedByRequestDataException::class);
});

test('cookie identification works', function () {
    $tenant = Tenant::create();

    // Default cookie name
    $this->withoutExceptionHandling()->withUnencryptedCookie('tenant', $tenant->id)->get('test')->assertSee($tenant->id);

    // Custom cookie name
    config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.cookie' => 'custom_tenant_id']);
    $this->withoutExceptionHandling()->withUnencryptedCookie('custom_tenant_id', $tenant->id)->get('test')->assertSee($tenant->id);

    // Setting the cookie to null disables cookie identification
    config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.cookie' => null]);
    expect(fn () => $this->withoutExceptionHandling()->withUnencryptedCookie('tenant', $tenant->id)->get('test'))->toThrow(TenantCouldNotBeIdentifiedByRequestDataException::class);
});

// todo@tests encrypted cookie

test('an exception is thrown when no tenant data is not provided in the request', function () {
    pest()->expectException(TenantCouldNotBeIdentifiedByRequestDataException::class);
    $this->withoutExceptionHandling()->get('test');
});


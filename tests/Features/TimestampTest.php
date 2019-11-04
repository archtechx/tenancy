<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Feature;

use Stancl\Tenancy\Features\Timestamps;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\Tests\TestCase;

class TimestampTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.features' => [
            Timestamps::class,
        ]]);
    }

    /** @test */
    public function create_and_update_timestamps_are_added_on_create()
    {
        $tenant = Tenant::new()->save();
        $this->assertArraySubset(['created_at', 'updated_at'], $tenant->data);
    }

    /** @test */
    public function update_timestamps_are_added()
    {
        $tenant = Tenant::new()->save();
        $this->assertSame($tenant->created_at, $tenant->updated_at);

        $this->assert($tenant->updated_at > $tenant->created_at);
    }

    /** @test */
    public function softdelete_timestamps_are_added()
    {
        $this->assertSame();
    }
}

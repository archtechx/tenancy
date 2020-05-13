<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Tests\TestCase;

class EventListenerTest extends TestCase
{
    /** @test */
    public function listeners_can_be_synchronous()
    {
        Queue::fake();
        Event::listen(TenantCreated::class, FooListener::class);

        Tenant::create();

        Queue::assertNothingPushed();

        $this->assertSame('bar', app('foo'));
    }

    /** @test */
    public function listeners_can_be_queued_by_setting_a_static_property()
    {
        Queue::fake();
        
        Event::listen(TenantCreated::class, FooListener::class);
        FooListener::$shouldQueue = true;

        Tenant::create();

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
            return $job->class === FooListener::class;
        });

        $this->assertFalse(app()->bound('foo'));
    }
}

class FooListener extends QueueableListener
{
    public static $shouldQueue = false;

    public function handle()
    {
        app()->instance('foo', 'bar');
    }
}

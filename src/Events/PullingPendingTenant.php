<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Events;

/**
 * Importantly, listeners for this event should not switch tenancy context.
 *
 * This event is fired from within a database transaction.
 */
class PullingPendingTenant extends Contracts\TenantEvent {}

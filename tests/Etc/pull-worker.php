<?php

declare(strict_types=1);

/**
 * Worker for the "concurrent pulls" tests.
 * Separate OS process that boots the same test env and calls pullPendingFromPool().
 * Since multiple processes run at once, they race for the pool.
 *
 * Used like `php pull-worker.php <startAtUnixFloat> <firstOrCreate:0|1>`
 *
 * Outputs the key of the pulled tenant, or "null" if nothing was pulled.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Tests\TestCase;

$startAt = (float) ($argv[1] ?? 0);
$firstOrCreate = ($argv[2] ?? '0') === '1';

// createApplication() replays the suite's central-MySQL config without running setUp(),
// so the pending tenants the parent test created survive into this process.
(new class('pull-worker') extends TestCase {})->createApplication();

// Wait so that every worker pulls at the same time
if ($startAt > 0.0) {
    time_sleep_until($startAt);
}

$tenant = Tenant::pullPendingFromPool($firstOrCreate);

fwrite(STDOUT, $tenant?->getKey() ?? 'null');

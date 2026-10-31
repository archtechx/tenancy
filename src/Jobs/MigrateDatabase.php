<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class MigrateDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Should pending tenants be included while migrating,
     * regardless of the tenancy.pending.include_in_queries config value.
     *
     * If false, pending tenants will be specifically excluded.
     *
     * If null, default to tenancy.pending.include_in_queries config.
     */
    public static ?bool $includePending = true;

    public function __construct(
        protected TenantWithDatabase&Model $tenant,
    ) {}

    public function handle(): void
    {
        $exitCode = Artisan::call('tenants:migrate', [
            '--tenants' => [$this->tenant->getTenantKey()],
            '--with-pending' => static::$includePending ?? config('tenancy.pending.include_in_queries'),
        ]);

        // Throw instead of returning false so that the failure isn't silently
        // swallowed by the pipeline, and so that queued pipelines fail loudly
        if ($exitCode !== 0) {
            throw new RuntimeException("tenants:migrate failed for tenant {$this->tenant->getTenantKey()} with exit code {$exitCode}. Output: " . trim(Artisan::output()));
        }
    }
}

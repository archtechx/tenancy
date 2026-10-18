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

class SeedDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Should pending tenants be included while seeding,
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
        $exitCode = Artisan::call('tenants:seed', [
            '--tenants' => [$this->tenant->getTenantKey()],
            '--with-pending' => static::$includePending ?? config('tenancy.pending.include_in_queries'),
        ]);

        // Throw instead of returning false so that the failure isn't silently
        // swallowed by the pipeline, and so that queued pipelines fail loudly
        if ($exitCode !== 0) {
            $message = "tenants:seed failed for tenant {$this->tenant->getTenantKey()} with exit code {$exitCode}.";

            // Most likely cause: the command declined to run in production without --force
            if (app()->isProduction() && ! (config('tenancy.seeder_parameters')['--force'] ?? false)) {
                $message .= " To seed tenant databases in production, set '--force' => true in tenancy.seeder_parameters,"
                    . ' or remove SeedDatabase from the pipeline if seeding in production is not intended.';
            }

            throw new RuntimeException($message . ' Output: ' . trim(Artisan::output()));
        }
    }
}

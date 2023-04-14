<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class DeleteDomains
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var TenantWithDatabase&Model $tenant
     * @param TenantWithDatabase&Model $tenant
     */
    public function __construct(
        protected TenantWithDatabase $tenant
    ) {
    }

    public function handle(): void
    {
        $this->tenant->domains->each->delete();
    }
}

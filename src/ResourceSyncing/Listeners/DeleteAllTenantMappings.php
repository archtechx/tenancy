<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Listeners\QueueableListener;

/**
 * Cleans up pivot records related to the deleted tenant.
 *
 * The listener only cleans up the pivot tables specified
 * in the $pivotTables property (see the property for details),
 * and is intended for use with tables that do not have tenant
 * foreign key constraints with onDelete('cascade').
 */
class DeleteAllTenantMappings extends QueueableListener
{
    public static bool $shouldQueue = false;

    /**
     * Pivot tables to clean up after a tenant is deleted, in the
     * ['table_name' => 'tenant_key_column'] format.
     *
     * Since we cannot automatically detect which pivot tables
     * are being used, they have to be specified here manually.
     *
     * The default value follows the polymorphic table used by default.
     */
    public static array $pivotTables = ['tenant_resources' => 'tenant_id'];

    public function handle(TenantDeleted $event): void
    {
        foreach (static::$pivotTables as $table => $tenantKeyColumn) {
            DB::table($table)->where($tenantKeyColumn, $event->tenant->getKey())->delete();
        }
    }
}

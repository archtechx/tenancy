<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Listeners\QueueableListener;

/**
 * Clean up pivot records related to the deleted tenant.
 * The listener only cleans up the pivot tables specified
 * in the $pivotTables property (see the property for details),
 * and is intended for use with tables that do not have tenant foreign key constraints.
 *
 * When using foreign key constraints, you'll still have to use ->onDelete('cascade')
 * on the constraint (otherwise, deleting a tenant will throw a foreign key constraint violation).
 * That way, the cleanup will happen on the database level, and this listener will essentially
 * just perform an extra 'where' query.
 */
class DeleteAllTenantMappings extends QueueableListener
{
    /**
     * Pivot tables to clean up after a tenant is deleted,
     * formatted like ['table_name' => 'tenant_key_column'].
     *
     * Since we cannot automatically detect which pivot tables
     * you want to clean up, they have to be specified here.
     *
     * By default, resource syncing uses the tenant_resources table, and the records are associated
     * to tenants by the tenant_id column (thus the ['tenant_resources' => 'tenant_id'] default).
     *
     * To customize this, set this property, e.g. in TenancyServiceProvider:
     * DeleteAllTenantMappings::$pivotTables = [
     *     'tenant_users' => 'tenant_id',
     *     // You can also add more pivot tables here
     * ];
     */
    public static array $pivotTables = ['tenant_resources' => 'tenant_id'];

    public function handle(TenantDeleted $event): void
    {
        foreach (static::$pivotTables as $table => $tenantKeyColumn) {
            DB::table($table)->where($tenantKeyColumn, $event->tenant->getTenantKey())->delete();
        }
    }
}

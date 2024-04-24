<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Used for easily dropping RLS policies on tables, primarily in migrations.
 */
trait ManagesRLSPolicies
{
    /** @return string[] */
    public static function getRLSPolicies(string $table): array
    {
        return array_map(
            fn (stdClass $policy) => $policy->policyname,
            DB::select("SELECT policyname FROM pg_policies WHERE tablename = '{$table}' AND policyname LIKE '%_rls_policy%'")
        );
    }

    public static function dropRLSPolicies(string $table): int
    {
        $policies = static::getRLSPolicies($table);

        foreach ($policies as $policy) {
            DB::statement('DROP POLICY ? ON ?', [$policy, $table]);
        }

        return count($policies);
    }
}

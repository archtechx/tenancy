<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseConfig;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledPostgreSQLSchemaManager;
use Stancl\Tenancy\RLS\PolicyManagers\RLSPolicyManager;

/**
 * Creates RLS policies for tables of models related to the tenants table.
 *
 * This command is used with Postgres + single-database tenancy, specifically when using RLS.
 */
class CreateUserWithRLSPolicies extends Command
{
    protected $signature = 'tenants:rls {--force= : Create RLS policies even if they already exist.}';

    protected $description = "Creates RLS policies for all tables related to the tenant table. Also creates the RLS user if it doesn't exist yet";

    public function handle(PermissionControlledPostgreSQLSchemaManager $manager): int
    {
        $username = config('tenancy.rls.user.username');
        $password = config('tenancy.rls.user.password');

        if ($username === null || $password === null) {
            $this->components->error('The RLS user credentials are not set in the "tenancy.rls.user" config.');

            return Command::FAILURE;
        }

        $this->components->info(
            $manager->createUser($this->makeDatabaseConfig($manager, $username, $password))
                ? "RLS user '{$username}' has been created."
                : "RLS user '{$username}' already exists."
        );

        $this->createTablePolicies();

        return Command::SUCCESS;
    }

    protected function enableRLS(string $table): void
    {
        // Enable RLS scoping on the table (without this, queries won't be scoped using RLS)
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        /**
         * Force RLS scoping on the table, so that the table owner users
         * don't bypass the scoping – table owners bypass RLS by default.
         *
         * E.g. when using a custom implementation where you create tables as the RLS user,
         * the queries won't be scoped for the RLS user unless we force the RLS scoping using this query.
         */
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Create a DatabaseConfig instance for the RLS user,
     * so that the user can get created using $manager->createUser($databaseConfig).
     */
    protected function makeDatabaseConfig(
        PermissionControlledPostgreSQLSchemaManager $manager,
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): DatabaseConfig {
        /** @var TenantWithDatabase $tenantModel */
        $tenantModel = tenancy()->model();

        // Use a temporary DatabaseConfig instance to set the host connection
        $temporaryDbConfig = $tenantModel->database();

        $temporaryDbConfig->purgeHostConnection();

        $tenantHostConnectionName = $temporaryDbConfig->getTenantHostConnectionName();
        config(["database.connections.{$tenantHostConnectionName}" => $temporaryDbConfig->hostConnection()]);

        // Use the tenant host connection in the manager
        $manager->setConnection($tenantModel->database()->getTenantHostConnectionName());

        // Set the database name (= central schema name/search_path in this case), username, and password
        $tenantModel->setInternal('db_name', $manager->connection()->getConfig('search_path'));
        $tenantModel->setInternal('db_username', $username);
        $tenantModel->setInternal('db_password', $password);

        return $tenantModel->database();
    }

    protected function createTablePolicies(): void
    {
        /** @var RLSPolicyManager $rlsPolicyManager */
        $rlsPolicyManager = app(config('tenancy.rls.manager'));
        $rlsQueries = $rlsPolicyManager->generateQueries();

        $zombiePolicies = $this->dropZombiePolicies(array_keys($rlsQueries));

        if ($zombiePolicies > 0) {
            $this->components->warn("Dropped {$zombiePolicies} zombie RLS policies.");
        }

        $createdPolicies = [];

        foreach ($rlsQueries as $table => $queries) {
            foreach ($queries as $type => $query) {
                [$hash, $policyQuery] = $this->hashPolicy($query);
                $expectedName = $table . '_' . $type . '_rls_policy_' . $hash;

                $tableRLSPolicy = $this->findTableRLSPolicy($table, $type);
                $olderPolicyExists = $tableRLSPolicy && $tableRLSPolicy->policyname !== $expectedName;

                // Drop the policy if an outdated version exists
                // or if it exists (even in the current form) and the --force option is used
                $dropPolicy = $olderPolicyExists || ($tableRLSPolicy && $this->option('force'));

                if ($tableRLSPolicy && $dropPolicy) {
                    DB::statement("DROP POLICY {$tableRLSPolicy->policyname} ON {$table}");

                    $this->components->info("RLS policy for table '{$table}' for '{$type}' dropped.");
                }

                // Create RLS policy if the table doesn't have it or if the --force option is used
                $createPolicy = $dropPolicy || ! $tableRLSPolicy || $this->option('force');

                if ($createPolicy) {
                    DB::statement($policyQuery);

                    $this->enableRLS($table);

                    $createdPolicies[] = $table . " ($hash)";
                } else {
                    $this->components->info("Table '{$table}' for '{$type}' already has an up to date RLS policy.");
                }
            }
        }

        if (! empty($createdPolicies)) {
            $managerName = str($rlsPolicyManager::class)->afterLast('\\')->toString();

            $this->components->info("RLS policies created for tables (using {$managerName}):");

            $this->components->bulletList($createdPolicies);

            $this->components->info('RLS policies updated successfully.');
        } else {
            $this->components->info('All RLS policies are up to date.');
        }
    }

    /** @return \stdClass|null */
    protected function findTableRLSPolicy(string $table, string $type): object|null
    {
        return DB::selectOne(<<<SQL
            SELECT * FROM pg_policies
            WHERE tablename = '{$table}'
            AND policyname LIKE '{$table}_{$type}_rls_policy%';
        SQL);
    }

    /**
     * Converts a raw RLS policy query into a "versioned" query
     * where the policy name is suffixed with a hash of the policy body.
     *
     * Returns the hash and the versioned query as a tuple.
     *
     * @return array{string, string}
     */
    public function hashPolicy(string $query): array
    {
        $lines = explode("\n", $query);

        // We split the query into the first line, the last line, and the actual body in between
        $firstLine = array_shift($lines);
        $lastLine = array_pop($lines);
        $body = implode("\n", $lines);

        // We update the policy name on the first line to contain a hash of the policy body
        // to keep track of the version of the policy
        $hash = substr(sha1($body), 0, 6);
        $firstLine = str_replace('_policy ON ', "_policy_{$hash} ON ", $firstLine);
        $policyQuery = $firstLine . "\n" . $body . "\n" . $lastLine;

        return [$hash, $policyQuery];
    }

    /**
     * Here we handle an edge case where a table may have an existing RLS policy
     * but is not included in $rlsQueries, this can happen e.g. when changing $scopeByDefault.
     * For these tables -- that have an existing policy but now SHOULDN'T have one -- we drop
     * the existing policies.
     */
    protected function dropZombiePolicies(array $tablesThatShouldHavePolicies): int
    {
        /** @var \stdClass[] $tablesWithRLSPolicies */
        $tablesWithRLSPolicies = DB::select("SELECT tablename, policyname FROM pg_policies WHERE policyname LIKE '%_rls_policy%'");

        $zombies = 0;

        foreach ($tablesWithRLSPolicies as $table) {
            if (! in_array($table->tablename, $tablesThatShouldHavePolicies, true)) {
                DB::statement("DROP POLICY {$table->policyname} ON {$table->tablename}");

                $this->components->warn("RLS policy for table '{$table->tablename}' dropped (zombie).");
                $zombies++;
            }
        }

        return $zombies;
    }
}

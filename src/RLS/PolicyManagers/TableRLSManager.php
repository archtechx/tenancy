<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\PolicyManagers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Exceptions\RecursiveRelationshipException;
use Stancl\Tenancy\RLS\Exceptions\RLSCommentConstraintException;

/**
 * Generates queries for creating RLS policies
 * for tables related to the tenants table.
 *
 * Usage:
 * // Generate queries for creating RLS policies.
 * // The queries will be returned in this format:
 * // [
 * //      <<<SQL
 * //          CREATE POLICY authors_rls_policy ON authors USING (
 * //              tenant_id::text = current_setting('my.current_tenant')
 * //          );
 * //      SQL,
 * //      <<<SQL
 * //          CREATE POLICY posts_rls_policy ON posts USING (
 * //              author_id IN (
 * //                  SELECT id
 * //                  FROM authors
 * //                  WHERE tenant_id::text = current_setting('my.current_tenant')
 * //              )
 * //          );
 * //      SQL,
 * // ]
 * // This is used In the CreateUserWithRLSPolicies command.
 * // Calls shortestPaths() internally to generate paths, then generates queries for each path.
 * $queries = app(TableRLSManager::class)->generateQueries();
 *
 * // Generate the shortest path from table X to the tenants table.
 * // Calls shortestPathToTenantsTable() recursively.
 * // The paths will be returned in this format:
 * // [
 * //     'foo_table' => [...$stepsLeadingToTenantsTable],
 * //     'bar_table' => [
 * //         [
 * //             'localColumn' => 'post_id',
 * //             'foreignTable' => 'posts',
 * //             'foreignColumn' => 'id'
 * //         ],
 * //         [
 * //             'localColumn' => 'tenant_id',
 * //             'foreignTable' => 'tenants',
 * //             'foreignColumn' => 'id'
 * //         ],
 * //     ],
 * // This is used in the CreateUserWithRLSPolicies command.
 * $shortestPath = app(TableRLSManager::class)->shortestPaths();
 *
 * generateQueries() and shortestPaths() methods are the only public methods of this class.
 * The rest of the methods are protected, and only used internally.
 * To see how they're structured and how they work, you can check their annotations.
 */
class TableRLSManager implements RLSPolicyManager
{
    /**
     * When true, all valid constraints are considered while generating paths for RLS policies,
     * unless explicitly marked with a 'no-rls' comment.
     *
     * When false, only columns explicitly marked with 'rls' or 'rls table.column' comments are considered.
     */
    public static bool $scopeByDefault = true;

    public function __construct(
        protected DatabaseManager $database
    ) {}

    /**
     * Generate queries that will be executed by the tenants:rls command
     * for creating RLS policies for all tables related to the tenants table
     * or for a passed array of paths.
     *
     * The passed paths should be formatted like this:
     * [
     *     'table_name' => [...$stepsLeadingToTenantsTable]
     * ]
     */
    public function generateQueries(array $paths = []): array
    {
        $queries = [];

        foreach ($paths ?: $this->shortestPaths() as $table => $path) {
            $queries[$table] = $this->generateQuery($table, $path);
        }

        return $queries;
    }

    /**
     * Generate shortest paths from each table to the tenants table,
     * structured like ['table_foo' => $shortestPathFromFoo, 'table_bar' => $shortestPathFromBar].
     *
     * For example:
     *
     * 'posts' => [
     *     [
     *         'localColumn' => 'tenant_id',
     *         'foreignTable' => 'tenants',
     *         'foreignColumn' => 'id'
     *     ],
     * ],
     * 'comments' => [
     *     [
     *         'localColumn' => 'post_id',
     *         'foreignTable' => 'posts',
     *         'foreignColumn' => 'id'
     *     ],
     *     [
     *         'localColumn' => 'tenant_id',
     *         'foreignTable' => 'tenants',
     *         'foreignColumn' => 'id'
     *     ],
     * ],
     *
     * @throws RecursiveRelationshipException When tables have recursive relationships and no other valid paths
     * @throws RLSCommentConstraintException When comment constraints are malformed
     */
    public function shortestPaths(): array
    {
        $shortestPaths = [];

        foreach ($this->getTableNames() as $tableName) {
            // Generate the shortest path from table named $tableName to the tenants table
            $shortestPath = $this->shortestPathToTenantsTable($tableName);

            if ($this->isValidPath($shortestPath)) {
                // Format path steps to a more readable format (keep only the needed data)
                $shortestPaths[$tableName] = array_map(fn (array $step) => [
                    'localColumn' => $step['localColumn'],
                    'foreignTable' => $step['foreignTable'],
                    'foreignColumn' => $step['foreignColumn'],
                ], $shortestPath['steps']);
            }

            // No valid path found. The shortest path either
            // doesn't lead to the tenants table (ignore),
            // or leads through a recursive relationship (throw an exception).
            if ($shortestPath['recursive_relationship']) {
                throw new RecursiveRelationshipException(
                    "Table '{$tableName}' has recursive relationships with no other valid paths to the tenants table."
                );
            }
        }

        return $shortestPaths;
    }

    /**
     * Create a path array with the given parameters.
     * This method serves as a 'single source of truth' for the path array structure.
     *
     * The 'steps' key contains the path steps returned by shortestPaths().
     * The 'dead_end' and 'recursive_relationship' keys are just internal metadata.
     *
     * @param bool $deadEnd Whether the path is a dead end (no valid constraints leading to tenants table)
     * @param bool $recursive Whether the path has recursive relationships
     * @param array $steps Steps to the tenants table, each step being a formatted constraint
     */
    protected function buildPath(bool $deadEnd = false, bool $recursive = false, array $steps = []): array
    {
        return [
            'dead_end' => $deadEnd,
            'recursive_relationship' => $recursive,
            'steps' => $steps,
        ];
    }

    /**
     * Formats the retrieved constraint to a more readable format.
     *
     * Also provides internal metadata about
     * - the constraint's nullability (the 'nullable' key),
     * - the constraint's comment
     *
     * These internal details are then omitted
     * from the constraints (or the "path steps")
     * before returning the shortest paths in shortestPath().
     *
     * [
     *    'localColumn' => 'tenant_id',
     *    'foreignTable' => 'tenants',
     *    'foreignColumn' => 'id',
     *    'comment' => 'no-rls', // Used to explicitly enable/disable RLS or to create a comment constraint (internal metadata)
     *    'nullable' => false, // Used to determine if the constraint is nullable (internal metadata)
     * ].
     */
    protected function formatForeignKey(array $constraint, string $table): array
    {
        assert(count($constraint['columns']) === 1);

        $localColumn = $constraint['columns'][0];

        $comment = collect($this->database->getSchemaBuilder()->getColumns($table))
                ->filter(fn ($column) => $column['name'] === $localColumn)
                ->first()['comment'] ?? null;

        $columnIsNullable = $this->database->selectOne(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $localColumn]
        )->is_nullable === 'YES';

        assert(count($constraint['foreign_columns']) === 1);

        return $this->formatConstraint(
            localColumn: $localColumn,
            foreignTable: $constraint['foreign_table'],
            foreignColumn: $constraint['foreign_columns'][0],
            comment: $comment,
            nullable: $columnIsNullable
        );
    }

    /** Single source of truth for our constraint format. */
    protected function formatConstraint(
        string $localColumn,
        string $foreignTable,
        string $foreignColumn,
        string|null $comment,
        bool $nullable
    ): array {
        return [
            'localColumn' => $localColumn,
            'foreignTable' => $foreignTable,
            'foreignColumn' => $foreignColumn,
            // Internal metadata omitted in shortestPaths()
            'comment' => $comment,
            'nullable' => $nullable,
        ];
    }

    /**
     * Recursively traverse a table's constraints to find
     * the shortest path to the tenants table.
     *
     * The shortest paths are cached in $cachedPaths to avoid
     * generating them for already visited tables repeatedly.
     *
     * @param string $table The table to find a path from
     * @param array &$cachedPaths Reference to array where discovered shortest paths are cached (including dead ends)
     * @param array $visitedTables Already visited tables (used for detecting recursive relationships)
     * @return array Paths with 'steps' (arrays of formatted constraints), 'dead_end' flag (bool), and 'recursive_relationship' flag (bool).
     */
    protected function shortestPathToTenantsTable(
        string $table,
        array &$cachedPaths = [],
        array $visitedTables = []
    ): array {
        // Return the shortest path for this table if it was already found and cached
        if (isset($cachedPaths[$table])) {
            return $cachedPaths[$table];
        }

        // Reached tenants table (last step)
        if ($table === tenancy()->model()->getTable()) {
            // This pretty much just means we set $cachedPaths['tenants'] to an
            // empty path. The significance of an empty path is that this class
            // considers it to mean "you are at the tenants table".
            $cachedPaths[$table] = $this->buildPath();

            return $cachedPaths[$table];
        }

        $constraints = $this->getConstraints($table);

        if (empty($constraints)) {
            // Dead end
            $cachedPaths[$table] = $this->buildPath(deadEnd: true);

            return $cachedPaths[$table];
        }

        /**
         * Find the optimal path from a table to the tenants table.
         *
         * Gather table's constraints (both foreign key constraints and comment constraints)
         * and recursively find shortest paths through each constraint (non-nullable paths are preferred for reliability).
         *
         * Handle recursive relationships by skipping paths that would create loops.
         * If there's no valid path in the end, and the table has recursive relationships,
         * an appropriate exception is thrown.
         *
         * At the end, it returns the shortest non-nullable path if available,
         * fall back to the overall shortest path.
         */
        $visitedTables = [...$visitedTables, $table];
        $shortestPath = [];
        $hasRecursiveRelationships = false;
        $hasValidPaths = false;

        foreach ($constraints as $constraint) {
            $foreignTable = $constraint['foreignTable'];

            // Skip constraints that would create loops
            if (in_array($foreignTable, $visitedTables)) {
                $hasRecursiveRelationships = true;
                continue;
            }

            // Recursive call
            $pathThroughConstraint = $this->shortestPathToTenantsTable(
                $foreignTable,
                $cachedPaths,
                $visitedTables
            );

            if ($pathThroughConstraint['recursive_relationship']) {
                $hasRecursiveRelationships = true;
                continue;
            }

            // Skip dead ends
            if ($pathThroughConstraint['dead_end']) {
                continue;
            }

            $hasValidPaths = true;
            $path = $this->buildPath(steps: array_merge([$constraint], $pathThroughConstraint['steps']));

            if ($this->isPathPreferable($path, $shortestPath)) {
                $shortestPath = $path;
            }
        }

        // Handle tables with only recursive relationships
        if ($hasRecursiveRelationships && ! $hasValidPaths) {
            // Don't cache paths that cause recursion - return right away.
            // This allows tables with recursive relationships to be processed again.
            // Example:
            // - posts table has highlighted_comment_id that leads to the comments table
            // - comments table has recursive_post_id that leads to the posts table (recursive relationship),
            // - comments table also has tenant_id which leads to the tenants table (a valid path).
            // If the recursive path got cached first, the path leading directly through tenants would never be found.
            return $this->buildPath(recursive: true);
        }

        $cachedPaths[$table] = $shortestPath ?: $this->buildPath(deadEnd: true);

        return $cachedPaths[$table];
    }

    /**
     * Get all valid relationship constraints for a table. The constraints are also formatted.
     * Combines both standard foreign key constraints and comment constraints.
     *
     * The schema builder retrieves foreign keys in the following format:
     * [
     *     'name' => 'posts_tenant_id_foreign',
     *     'columns' => ['tenant_id'],
     *     'foreign_table' => 'tenants',
     *     'foreign_columns' => ['id'],
     *     ...
     * ]
     *
     * We format that into a more readable format using formatForeignKey(),
     * and that method uses formatConstraint(), which serves as a single source of truth
     * for our constraint formatting. A formatted constraint looks like this:
     * [
     *     'localColumn' => 'tenant_id',
     *     'foreignTable' => 'tenants',
     *     'foreignColumn' => 'id',
     *     'comment' => 'no-rls',
     *     'nullable' => false
     * ]
     *
     * The comment constraints are retrieved using getFormattedCommentConstraints().
     * These constraints are formatted in the method itself.
     */
    protected function getConstraints(string $table): array
    {
        $formattedConstraints = array_merge(
            array_map(
                fn ($schemaStructure) => $this->formatForeignKey($schemaStructure, $table),
                $this->database->getSchemaBuilder()->getForeignKeys($table)
            ),
            $this->getFormattedCommentConstraints($table)
        );

        $validConstraints = [];

        foreach ($formattedConstraints as $constraint) {
            if (! $this->shouldSkipPathLeadingThroughConstraint($constraint)) {
                $validConstraints[] = $constraint;
            }
        }

        return $validConstraints;
    }

    /**
     * Determine if a path leading through the passed constraint
     * should be excluded from choosing the shortest path
     * based on the constraint's comment.
     *
     * If $scopeByDefault is true, only skip paths leading through constraints flagged with the 'no-rls' comment.
     * If $scopeByDefault is false, skip paths leading through any constraint, unless the key has explicit 'rls' or 'rls table.column' comments.
     *
     * @param array $constraint Formatted constraint
     */
    protected function shouldSkipPathLeadingThroughConstraint(array $constraint): bool
    {
        $comment = $constraint['comment'] ?? null;

        // Always skip constraints with the 'no-rls' comment
        if ($comment === 'no-rls') {
            return true;
        }

        if (static::$scopeByDefault) {
            return false;
        }

        // When $scopeByDefault is false, skip every constraint
        // with a comment that doesn't start with 'rls'.
        if (! is_string($comment)) {
            return true;
        }

        // Explicit scoping
        if ($comment === 'rls') {
            return false;
        }

        // Comment constraint
        if (Str::startsWith($comment, 'rls ')) {
            return false;
        }

        return true;
    }

    /**
     * Retrieve a table's comment constraints.
     *
     * Comment constraints are columns with comments
     * structured like "rls <foreign_table>.<foreign_column>".
     *
     * Returns an array of formatted comment constraints (check formatConstraint() to see the format).
     */
    protected function getFormattedCommentConstraints(string $tableName): array
    {
        $commentConstraints = array_filter($this->database->getSchemaBuilder()->getColumns($tableName), function ($column) {
            return (isset($column['comment']) && is_string($column['comment']))
                && Str::startsWith($column['comment'], 'rls ');
        });

        // Validate and format the comment constraints
        $commentConstraints = array_map(
            fn ($commentConstraint) => $this->parseCommentConstraint($commentConstraint, $tableName),
            $commentConstraints
        );

        return $commentConstraints;
    }

    /**
     * Parse and validate a comment constraint.
     *
     * This method validates that the table and column referenced
     * in the comment exist, formats and returns the constraint.
     *
     * @throws RLSCommentConstraintException When comment format is invalid or references don't exist
     */
    protected function parseCommentConstraint(array $commentConstraint, string $tableName): array
    {
        $comment = $commentConstraint['comment'];
        $columnName = $commentConstraint['name'];

        $builder = $this->database->getSchemaBuilder();
        $constraint = explode('.', Str::after($comment, 'rls '));

        // Validate comment constraint format
        if (count($constraint) !== 2 || empty($constraint[0]) || empty($constraint[1])) {
            throw new RLSCommentConstraintException("Malformed comment constraint on {$tableName}.{$columnName}: '{$comment}'");
        }

        $foreignTable = $constraint[0];
        $foreignColumn = $constraint[1];

        // Validate table existence
        if (! $builder->hasTable($foreignTable)) {
            throw new RLSCommentConstraintException("Comment constraint on {$tableName}.{$columnName} references non-existent table '{$foreignTable}'");
        }

        // Validate column existence
        if (! $builder->hasColumn($foreignTable, $foreignColumn)) {
            throw new RLSCommentConstraintException("Comment constraint on {$tableName}.{$columnName} references non-existent column '{$foreignTable}.{$foreignColumn}'");
        }

        // Return the formatted constraint
        return $this->formatConstraint(
            localColumn: $commentConstraint['name'],
            foreignTable: $foreignTable,
            foreignColumn: $foreignColumn,
            comment: $commentConstraint['comment'],
            nullable: $commentConstraint['nullable']
        );
    }

    /** Generates a query that creates a row-level security policy for the passed table. */
    protected function generateQuery(string $table, array $path): string
    {
        // Generate the SQL conditions recursively
        $query = "CREATE POLICY {$table}_rls_policy ON {$table} USING (\n";
        $sessionTenantKey = config('tenancy.rls.session_variable_name');

        foreach ($path as $index => $relation) {
            $column = $relation['localColumn'];
            $table = $relation['foreignTable'];
            $foreignKey = $relation['foreignColumn'];

            $indentation = str_repeat(' ', ($index + 1) * 4);

            $query .= $indentation;

            if ($index !== 0) {
                // On first loop, we don't use a WHERE
                $query .= 'WHERE ';
            }

            if ($table === tenancy()->model()->getTable()) {
                // Convert tenant key to text to match the session variable type
                $query .= "{$column}::text = current_setting('{$sessionTenantKey}')\n";
                continue;
            }

            $query .= "{$column} IN (\n";
            $query .= $indentation . "    SELECT {$foreignKey}\n";
            $query .= $indentation . "    FROM {$table}\n";
        }

        // Closing ) for each nested WHERE
        // -1 because the last item is the tenant table reference which is not a nested where
        for ($i = count($path) - 1; $i > 0; $i--) {
            $query .= str_repeat(' ', $i * 4) . ")\n";
        }

        $query .= ');'; // closing for CREATE POLICY

        return $query;
    }

    /** Returns unprefixed table names. */
    protected function getTableNames(): array
    {
        $builder = $this->database->getSchemaBuilder();
        $tables = [];

        foreach ($builder->getTableListing(schema: $this->database->getConfig('search_path')) as $table) {
            // E.g. "public.table_name" -> "table_name"
            $tables[] = str($table)->afterLast('.')->toString();
        }

        return $tables;
    }

    /**
     * Check if discovered path is valid for RLS policy generation.
     *
     * A valid path:
     * - leads to tenants table (isn't dead end)
     * - has at least one step (the tenants table itself will have no steps)
     */
    protected function isValidPath(array $path): bool
    {
        return ! $path['dead_end'] && ! empty($path['steps']);
    }

    /**
     * Determine if the passed path is preferred to the current shortest path.
     *
     * Non-nullable paths are preferred to nullable paths.
     * From paths of the same nullability, the shorter will be preferred.
     */
    protected function isPathPreferable(array $path, array $shortestPath): bool
    {
        if (! $shortestPath) {
            return true;
        }

        $pathIsNullable = $this->isPathNullable($path['steps']);
        $shortestPathIsNullable = $this->isPathNullable($shortestPath['steps']);

        // Prefer non-nullable
        if ($pathIsNullable !== $shortestPathIsNullable) {
            return ! $pathIsNullable;
        }

        // Prefer shorter
        return count($path['steps']) < count($shortestPath['steps']);
    }

    /** Determine if any step in the path is nullable. */
    protected function isPathNullable(array $path): bool
    {
        foreach ($path as $step) {
            if ($step['nullable']) {
                return true;
            }
        }

        return false;
    }
}

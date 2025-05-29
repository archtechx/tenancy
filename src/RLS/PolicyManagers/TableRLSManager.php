<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\PolicyManagers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Exceptions\RecursiveRelationshipException;
use Stancl\Tenancy\Exceptions\RLSCommentConstraintException;

class TableRLSManager implements RLSPolicyManager
{
    /**
     * When true, all foreign keys are considered for RLS unless explicitly marked with 'no-rls' comment.
     * When false, only columns explicitly marked with 'rls' or 'rls table.column' comments are considered.
     */
    public static bool $scopeByDefault = true;

    public function __construct(
        protected DatabaseManager $database
    ) {}

    /**
     * Generate queries that create RLS policies
     * for all tables related to the tenants table
     * or for a specified set of paths in format ['table' => [steps_to_tenants_table]].
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
     * structured like ['table_foo' => $shortestPathForFoo, 'table_bar' => $shortestPathForBar].
     *
     * For example:
     *
     * 'posts' => [
     *     [
     *         'foreignKey' => 'tenant_id',
     *         'foreignTable' => 'tenants',
     *         'foreignId' => 'id'
     *     ],
     * ],
     * 'comments' => [
     *     [
     *         'foreignKey' => 'post_id',
     *         'foreignTable' => 'posts',
     *         'foreignId' => 'id'
     *     ],
     *     [
     *         'foreignKey' => 'tenant_id',
     *         'foreignTable' => 'tenants',
     *         'foreignId' => 'id'
     *     ],
     * ],
     *
     * @throws RecursiveRelationshipException When tables have recursive relationships and no valid paths
     * @throws RLSCommentConstraintException When comment constraints are malformed
     */
    public function shortestPaths(): array
    {
        $cachedPaths = [];
        $results = [];

        foreach ($this->getTableNames() as $tableName) {
            $shortestPath = $this->shortestPathToTenantsTable($tableName, $cachedPaths);

            if ($this->isValidPath($shortestPath)) {
                $results[$tableName] = $this->preparePathForOutput($shortestPath['steps']);
            } elseif (isset($shortestPath['recursion']) && $shortestPath['recursion']) {
                throw new RecursiveRelationshipException(
                    "Table '{$tableName}' has recursive relationships with no valid paths to the tenants table."
                );
            }
        }

        return $results;
    }

    /**
     * Recursively traverse table's constraints to find
     * the shortest path to the tenants table.
     *
     * The shortest paths are cached in $cachedPaths to avoid
     * recalculating them for tables that have already been processed.
     *
     * @param string $table The table to find a path from
     * @param array &$cachedPaths Reference to array for caching discovered paths
     * @param array $visitedTables Tables that were already visited (used for detecting recursion)
     * @return array Path with 'steps' (array of formatted foreign keys), 'dead_end' flag (bool), and 'recursion' flag (bool).
     */
    protected function shortestPathToTenantsTable(
        string $table,
        array &$cachedPaths,
        array $visitedTables = []
    ): array {
        if (isset($cachedPaths[$table])) {
            return $cachedPaths[$table];
        }

        // Reached tenants table
        if ($table === tenancy()->model()->getTable()) {
            $cachedPaths[$table] = [
                'dead_end' => false,
                'recursion' => false,
                'steps' => [],
            ];

            return $cachedPaths[$table];
        }

        $foreignKeys = $this->getForeignKeys($table);

        if (empty($foreignKeys)) {
            // Dead end
            $cachedPaths[$table] = [
                'dead_end' => true,
                'recursion' => false,
                'steps' => [],
            ];

            return $cachedPaths[$table];
        }

        return $this->determineShortestPath($table, $foreignKeys, $cachedPaths, $visitedTables);
    }

    /**
     * Based on the foreign key's comment,
     * determine if a path leading through the passed foreign key
     * should be excluded from determining the shortest path.
     *
     * If static::$scopeByDefault is true, only skip paths explicitly marked with 'no-rls'.
     * If static::$scopeByDefault is false, skip paths unless they have 'rls' or 'rls table.column' comments.
     *
     * @param array $foreignKey Formatted foreign key (has to have the 'comment' key)
     */
    protected function shouldSkipPathLeadingThrough(array $foreignKey): bool
    {
        $comment = $foreignKey['comment'] ?? null;

        // Always skip paths explicitly marked with 'no-rls'
        if ($comment === 'no-rls') {
            return true;
        }

        // When scopeByDefault is true, include all paths except 'no-rls'
        if (static::$scopeByDefault) {
            return false;
        }

        // When scopeByDefault is false, only include paths with RLS comments
        if (! $comment || ! is_string($comment)) {
            return true;
        }

        return ! (Str::is($comment, 'rls') || Str::startsWith($comment, 'rls '));
    }

    /**
     * Parse and validate a comment-based constraint string.
     *
     * Comment constraints allow manually specifying relationships
     * using comments with format "rls table.column".
     *
     * This method parses such comments, validates that the referenced table and column exist,
     * and returns the constraint in a format corresponding with standardly retrieved foreign keys,
     * ready to be formatted using formatForeignKey().
     *
     * @throws RLSCommentConstraintException When comment format is invalid or references don't exist
     */
    protected function parseCommentConstraint(string $comment, string $tableName, string $columnName): array
    {
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

        return [
            'foreign_table' => $foreignTable,
            'foreign_columns' => [$foreignColumn],
            'columns' => [$columnName],
        ];
    }

    /**
     * Retrieve table's comment-based constraints. These are columns with comments
     * formatted like "rls <foreign_table>.<foreign_column>".
     *
     * Returns the constraints as unformatted foreign key arrays, ready to be passed to $this->formatForeignKey().
     */
    protected function getCommentConstraints(string $tableName): array
    {
        $commentConstraintColumns = array_filter($this->database->getSchemaBuilder()->getColumns($tableName), function ($column) {
            return (isset($column['comment']) && is_string($column['comment']))
                && Str::startsWith($column['comment'], 'rls ');
        });

        return array_map(
            fn ($column) => $this->parseCommentConstraint($column['comment'], $tableName, $column['name']),
            $commentConstraintColumns
        );
    }

    /**
     * Formats the foreign key retrieved by Postgres or comment-based constraint to a more readable format.
     *
     * Also provides information about whether the foreign key is nullable,
     * and the foreign key column comment. These additional details are removed
     * from the foreign keys/path steps before returning the final shortest paths.
     *
     * The 'comment' key gets deleted while generating the full trees (in shortestPaths()),
     * and the 'nullable' key gets deleted while generating the shortest paths (in findShortestPath()).
     *
     * [
     *    'foreignKey' => 'tenant_id',
     *    'foreignTable' => 'tenants',
     *    'foreignId' => 'id',
     *    'comment' => 'no-rls', // Foreign key comment – used to explicitly enable/disable RLS
     *    'nullable' => false, // Whether the foreign key is nullable
     * ].
     */
    protected function formatForeignKey(array $foreignKey, string $table): array
    {
        $foreignKeyName = $foreignKey['columns'][0];

        return [
            'foreignKey' => $foreignKeyName,
            'foreignTable' => $foreignKey['foreign_table'],
            'foreignId' => $foreignKey['foreign_columns'][0],
            // Internal metadata (deleted in shortestPaths())
            'comment' => $this->getComment($table, $foreignKeyName),
            'nullable' => $this->isColumnNullable($table, $foreignKeyName),
        ];
    }

    /** Check if a column is nullable. */
    protected function isColumnNullable(string $table, string $column): bool
    {
        $result = $this->database->selectOne(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $result?->is_nullable === 'YES';
    }

    /** Generates a query that creates a row-level security policy for the passed table. */
    protected function generateQuery(string $table, array $path): string
    {
        // Generate the SQL conditions recursively
        $query = "CREATE POLICY {$table}_rls_policy ON {$table} USING (\n";
        $sessionTenantKey = config('tenancy.rls.session_variable_name');

        foreach ($path as $index => $relation) {
            $column = $relation['foreignKey'];
            $table = $relation['foreignTable'];
            $foreignKey = $relation['foreignId'];

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

    protected function getComment(string $tableName, string $columnName): string|null
    {
        $column = collect($this->database->getSchemaBuilder()->getColumns($tableName))
                ->filter(fn ($column) => $column['name'] === $columnName)
                ->first();

        return $column['comment'] ?? null;
    }

    /** Returns true if any step in the path is nullable. */
    protected function isPathNullable(array $path): bool
    {
        foreach ($path as $step) {
            if ($step['nullable']) {
                return true;
            }
        }

        return false;
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
     * Check if a discovered path is valid for RLS policy generation.
     *
     * A path is considered valid if:
     * - it's not a dead end (leads to tenants table)
     * - it has at least one step (the tenants table itself will have no steps)
     */
    protected function isValidPath(array $path): bool
    {
        return ! $path['dead_end'] && ! empty($path['steps']);
    }

    /** Remove internal metadata ('comment', 'nullable') from path. */
    protected function preparePathForOutput(array $steps): array
    {
        return array_map(function ($step) {
            unset($step['comment'], $step['nullable']);

            return $step;
        }, $steps);
    }

    /**
     * Get all valid foreign key relationships for a table.
     * Combines both standard foreign key constraints and comment-based constraints.
     */
    protected function getForeignKeys(string $table): array
    {
        $constraints = array_merge(
            $this->database->getSchemaBuilder()->getForeignKeys($table),
            $this->getCommentConstraints($table)
        );

        $foreignKeys = [];

        foreach ($constraints as $constraint) {
            $formatted = $this->formatForeignKey($constraint, $table);

            if (! $this->shouldSkipPathLeadingThrough($formatted)) {
                $foreignKeys[] = $formatted;
            }
        }

        return $foreignKeys;
    }

    /**
     * Find the optimal path from a table to the tenants table.
     *
     * Gathers table's constraints (both foreign keys and comment-based constraints)
     * and recursively finds paths through each constraint while tracking both
     * the overall shortest path and the shortest non-nullable
     * path (non-nullable paths are preferred for reliability).
     *
     * Handles recursive relationships by skipping paths that would create loops.
     * If there's no valid path in the end, and the table has recursive relationships,
     * an appropriate exception is thrown.
     *
     * At the end, it returns the shortest non-nullable path if available,
     * falling back to the overall shortest path.
     *
     * @param string $table The table to find a path from
     * @param array $foreignKeys Array of foreign key relationships to explore
     * @param array &$cachedPaths Reference to caching array for memoization
     * @param array $visitedTables Tables already visited in this path (used for detecting recursion)
     * @return array Path with 'steps' array, 'dead_end' flag, and 'recursion' flag
     */
    protected function determineShortestPath(
        string $table,
        array $foreignKeys,
        array &$cachedPaths,
        array $visitedTables
    ): array {
        $visitedTables = [...$visitedTables, $table];
        $shortestPath = [];
        $hasRecursiveRelationships = false;
        $hasValidPaths = false;

        foreach ($foreignKeys as $foreign) {
            // Check if this specific foreign key would lead to recursion
            if (in_array($foreign['foreignTable'], $visitedTables)) {
                // This foreign key leads to a table we're already visiting - skip it
                $hasRecursiveRelationships = true;
                continue;
            }

            // Recursive call
            $foreignPath = $this->shortestPathToTenantsTable(
                $foreign['foreignTable'],
                $cachedPaths,
                $visitedTables
            );

            if (isset($foreignPath['recursion']) && $foreignPath['recursion']) {
                $hasRecursiveRelationships = true;
                continue;
            }

            if (! $foreignPath['dead_end']) {
                $hasValidPaths = true;
                // Build the full path with the current foreign key as the first step
                $path = [
                    'dead_end' => false,
                    'recursion' => false,
                    'steps' => array_merge([$foreign], $foreignPath['steps']),
                ];

                if ($this->determineBetterPath($path, $shortestPath)) {
                    $shortestPath = $path;
                }
            }
        }

        if ($hasRecursiveRelationships && ! $hasValidPaths) {
            $finalPath = [
                'dead_end' => false,
                'recursion' => true,
                'steps' => [],
            ];

            // Don't cache recursive paths -- return right away.
            // This allows tables with recursive relationships to be processed again
            // E.g. posts table has highlighted_comment_id -> comments
            // comments table has recursive_post_id -> posts (recursive),
            // and tenant_id -> tenants (valid).
            // If the recursive path got cached, the path leading directly through tenants would never be found.
            return $finalPath;
        } else {
            $finalPath = $shortestPath ?: [
                'dead_end' => true,
                'recursion' => false,
                'steps' => [],
            ];
        }

        $cachedPaths[$table] = $finalPath;

        return $finalPath;
    }

    /**
     * Determine if the passed path is better than the current shortest path.
     *
     * Non-nullable paths are preferred over nullable paths.
     * From paths of the same nullability, the shorter will be preferred.
     */
    protected function determineBetterPath(array $path, array $currentBestPath): bool
    {
        if (! $currentBestPath) {
            return true;
        }

        $pathIsNullable = $this->isPathNullable($path['steps']);
        $bestPathIsNullable = $this->isPathNullable($currentBestPath['steps']);

        // Prefer non-nullable
        if ($pathIsNullable !== $bestPathIsNullable) {
            return ! $pathIsNullable;
        }

        // Prefer shorter
        return count($path['steps']) < count($currentBestPath['steps']);
    }
}

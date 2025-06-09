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
     * When true, all valid foreign keys are considered while generating paths for RLS policies,
     * unless explicitly marked with 'no-rls' comment.
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
     *     'table_name' => [$stepLeadingToTenantsTable]
     * ].
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
        $shortestPaths = [];

        foreach ($this->getTableNames() as $tableName) {
            // Generate the shortest path from table named $tableName to the tenants table
            $shortestPath = $this->shortestPathToTenantsTable($tableName, $cachedPaths);

            if ($this->isValidPath($shortestPath)) {
                // Format path steps to a more readable format (keep only the needed data)
                $shortestPaths[$tableName] = array_map(fn (array $step) => [
                    'foreignKey' => $step['foreignKey'],
                    'foreignTable' => $step['foreignTable'],
                    'foreignId' => $step['foreignId'],
                ], $shortestPath['steps']);
            }

            // No valid path found. The shortest path either
            // doesn't lead to the tenants table (ignore),
            // or leads through a recursive relationship (throw an exception).
            if ($shortestPath['recursive_relationship']) {
                throw new RecursiveRelationshipException(
                    "Table '{$tableName}' has recursive relationships with no valid paths to the tenants table."
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
     * @param bool $deadEnd Whether the path is a dead end (no valid foreign keys leading to tenants table)
     * @param bool $recursive Whether the path has recursive relationships
     * @param array $steps The steps in the path, each step being an array of formatted foreign keys
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
     * Formats the retrieved foreign key to a more readable format.
     *
     * Also provides internal metadata about
     * - the foreign key's nullability (the 'nullable' key),
     * - the foreign key's comment
     *
     * These internal details are then omitted
     * from the foreign keys (or the "path steps")
     * before returning the shortest paths in shortestPath().
     *
     * [
     *    'foreignKey' => 'tenant_id',
     *    'foreignTable' => 'tenants',
     *    'foreignId' => 'id',
     *    'comment' => 'no-rls', // Used to explicitly enable/disable RLS or to create a comment constraint (internal metadata)
     *    'nullable' => false, // Used to determine if the foreign key is nullable (internal metadata)
     * ].
     */
    protected function formatForeignKey(array $foreignKey, string $table): array
    {
        $foreignKeyName = $foreignKey['columns'][0];

        $comment = collect($this->database->getSchemaBuilder()->getColumns($table))
                ->filter(fn ($column) => $column['name'] === $foreignKeyName)
                ->first()['comment'] ?? null;

        $columnIsNullable = $this->database->selectOne(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $foreignKeyName]
        )?->is_nullable === 'YES';

        return [
            'foreignKey' => $foreignKeyName,
            'foreignTable' => $foreignKey['foreign_table'],
            'foreignId' => $foreignKey['foreign_columns'][0],
            // Internal metadata omitted in shortestPaths()
            'comment' => $comment,
            'nullable' => $columnIsNullable,
        ];
    }

    /**
     * Recursively traverse table's constraints to find
     * the shortest path to the tenants table.
     *
     * The shortest paths are cached in $cachedPaths to avoid
     * generating them for already visited tables repeatedly.
     *
     * @param string $table The table to find a path from
     * @param array &$cachedPaths Reference to array where discovered shortest paths are cached (including dead ends)
     * @param array $visitedTables Already visited tables (used for detecting recursive relationships)
     * @return array Paths with 'steps' (arrays of formatted foreign keys), 'dead_end' flag (bool), and 'recursive_relationship' flag (bool).
     */
    protected function shortestPathToTenantsTable(
        string $table,
        array &$cachedPaths,
        array $visitedTables = []
    ): array {
        // Return the shortest path for this table if it was already found and cached
        if (isset($cachedPaths[$table])) {
            return $cachedPaths[$table];
        }

        // Reached tenants table (last step)
        if ($table === tenancy()->model()->getTable()) {
            $cachedPaths[$table] = $this->buildPath();

            return $cachedPaths[$table];
        }

        $foreignKeys = $this->getForeignKeys($table);

        if (empty($foreignKeys)) {
            // Dead end
            $cachedPaths[$table] = $this->buildPath(deadEnd: true);

            return $cachedPaths[$table];
        }

        return $this->determineShortestPath($table, $foreignKeys, $cachedPaths, $visitedTables);
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
     * Determine if a path leading through the passed foreign key
     * should be excluded from choosing the shortest path
     * based on the foreign key's comment.
     *
     * If static::$scopeByDefault is true, only skip paths leading through foreign keys flagged with the 'no-rls' comment.
     * If static::$scopeByDefault is false, skip paths leading through any foreign key, unless the key has explicit 'rls' or 'rls table.column' comments.
     *
     * @param array $foreignKey Formatted foreign key (has to have the 'comment' key)
     */
    protected function shouldSkipPathLeadingThrough(array $foreignKey): bool
    {
        $comment = $foreignKey['comment'] ?? null;

        // Always skip foreign keys with the 'no-rls' comment
        if ($comment === 'no-rls') {
            return true;
        }

        if (static::$scopeByDefault) {
            return false;
        }

        // When scopeByDefault is false, skip every foreign key
        // with a comment that doesn't start with 'rls'.
        if (! is_string($comment)) {
            return true;
        }

        return ! (Str::is($comment, 'rls') || Str::startsWith($comment, 'rls '));
    }

    /**
     * Retrieve table's comment-based constraints. These are columns with comments
     * formatted like "rls <foreign_table>.<foreign_column>".
     *
     * Returns the constraints as unformatted foreign key arrays, ready to be formatted by formatForeignKey().
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
     * Parse and validate a comment constraint.
     *
     * This method validates that the table and column referenced
     * in the comment exist, and returns the constraint in a format corresponding to the
     * standardly retrieved foreign keys (ready to be formatted using formatForeignKey()).
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
     * @return array Path with 'steps' array, 'dead_end' flag, and 'recursive_relationship' flag
     */
    protected function determineShortestPath(
        string $table,
        array $foreignKeys,
        array &$cachedPaths,
        array $visitedTables
    ): array
    {
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

            if ($foreignPath['recursive_relationship']) {
                $hasRecursiveRelationships = true;
                continue;
            }

            if (! $foreignPath['dead_end']) {
                $hasValidPaths = true;

                // Build the full path with the current foreign key as the first step
                $path = $this->buildPath(steps: array_merge([$foreign], $foreignPath['steps']));

                if ($this->isPathPreferable($path, $shortestPath)) {
                    $shortestPath = $path;
                }
            }
        }

        if ($hasRecursiveRelationships && ! $hasValidPaths) {
            // Don't cache paths that cause recursion - return right away.
            // This allows tables with recursive relationships to be processed again.
            // Example:
            // - posts table has highlighted_comment_id that leads to the comments table
            // - comments table has recursive_post_id that leads to the posts table (recursive relationship),
            // - comments table also has tenant_id which leadds to the tenants table (a valid path).
            // If the recursive path got cached first, the path leading directly through tenants would never be found.
            return $this->buildPath(recursive: true);
        } else {
            $finalPath = $shortestPath ?: $this->buildPath(deadEnd: true);
        }

        $cachedPaths[$table] = $finalPath;

        return $finalPath;
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

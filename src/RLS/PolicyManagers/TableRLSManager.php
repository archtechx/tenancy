<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\PolicyManagers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Exceptions\RecursiveRelationshipException;
use Stancl\Tenancy\Exceptions\RLSCommentConstraintException;

class TableRLSManager implements RLSPolicyManager
{
    public static bool $scopeByDefault = true;

    public function __construct(
        protected DatabaseManager $database
    ) {}

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

    protected function shouldSkipPathLeadingThrough(array $foreignKey): bool
    {
        // If the column has a comment of 'no-rls', we skip it.
        // Also skip the column if implicit scoping is off and the column
        // has no 'rls' comment or is not recognized as a comment constraint (its comment doesn't begin with 'rls ').
        $pathExplicitlySkipped = $foreignKey['comment'] === 'no-rls';
        $pathImplicitlySkipped = ! static::$scopeByDefault && (
            ! isset($foreignKey['comment']) ||
            (is_string($foreignKey['comment']) && ! (
                Str::is($foreignKey['comment'], 'rls') || // Explicit RLS
                Str::startsWith($foreignKey['comment'], 'rls ') // Comment constraint
            ))
        );

        return $pathExplicitlySkipped || $pathImplicitlySkipped;
    }

    /**
     * Parse and validate a comment-based constraint string.
     * Returns an array with foreignTable and foreignColumn.
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
     *
     * Throws an exception if the comment is formatted incorrectly or if the referenced table/column does not exist.
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
        // $foreignKey is an unformatted foreign key retrieved by $this->database->getSchemaBuilder()->getForeignKeys($table)
        return [
            'foreignKey' => $foreignKeyName = $foreignKey['columns'][0],
            'foreignTable' => $foreignKey['foreign_table'],
            'foreignId' => $foreignKey['foreign_columns'][0],
            // Internal metadata (deleted in shortestPaths())
            'comment' => $this->getComment($table, $foreignKeyName),
            'nullable' => $this->database->selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = '{$table}' AND column_name = '{$foreignKeyName}'")->is_nullable === 'YES',
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

    protected function getComment(string $tableName, string $columnName): string|null
    {
        $column = collect($this->database->getSchemaBuilder()->getColumns($tableName))
                ->filter(fn ($column) => $column['name'] === $columnName)
                ->first();

        return $column['comment'] ?? null;
    }

    /**
     * Returns true if any step in the path is nullable.
     */
    protected function isPathNullable(array $path): bool
    {
        foreach ($path as $step) {
            if ($step['nullable']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns unprefixed table names.
     */
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
     * Check if a path is valid (not a dead end and has steps).
     *
     * A path has 0 steps if it leads to a dead end,
     * or if it leads from the tenants table itself.
     */
    protected function isValidPath(array $path): bool
    {
        return ! $path['dead_end'] && ! empty($path['steps']);
    }

    /**
     * Clean path steps by removing internal metadata (comment, nullable).
     */
    protected function preparePathForOutput(array $steps): array
    {
        return array_map(function ($step) {
            unset($step['comment'], $step['nullable']);

            return $step;
        }, $steps);
    }

    /**
     * Format and return table's valid foreign keys.
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
     * Determine the shortest path from $table to the tenants table.
     *
     * Non-nullable paths are preferred.
     */
    protected function determineShortestPath(
        string $table,
        array $foreignKeys,
        array &$cachedPaths,
        array $visitedTables
    ): array {
        $visitedTables = [...$visitedTables, $table];
        // Initialize the length variables with maximum values
        $shortestLength = PHP_INT_MAX;
        $shortestNonNullableLength = PHP_INT_MAX;
        $shortestPath = null;
        $shortestNonNullablePath = null;
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

            if ($this->isRecursivePath($foreignPath)) {
                $hasRecursiveRelationships = true;
                continue;
            }

            if (! $foreignPath['dead_end']) {
                $hasValidPaths = true;
                $path = $this->buildPath($foreign, $foreignPath);

                $length = count($path['steps']);
                $isNullable = $this->isPathNullable($path['steps']);

                // Update shortest path
                if ($length < $shortestLength) {
                    $shortestLength = $length;
                    $shortestPath = $path;
                }

                // Update shortest non-nullable path
                if (! $isNullable && $length < $shortestNonNullableLength) {
                    $shortestNonNullableLength = $length;
                    $shortestNonNullablePath = $path;
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
            $finalPath = $shortestNonNullablePath ?? $shortestPath ?? [
                'dead_end' => true,
                'recursion' => false,
                'steps' => [],
            ];
        }

        $cachedPaths[$table] = $finalPath;

        return $finalPath;
    }

    /**
     * Check if a path is recursive.
     */
    protected function isRecursivePath(array $path): bool
    {
        return isset($path['recursion']) && $path['recursion'];
    }

    /**
     * Build a complete path by combining constraint with foreign path.
     */
    protected function buildPath(array $constraint, array $foreignPath): array
    {
        return [
            'dead_end' => false,
            'recursion' => false,
            'steps' => array_merge([$constraint], $foreignPath['steps']),
        ];
    }
}

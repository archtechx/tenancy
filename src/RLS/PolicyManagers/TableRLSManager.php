<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\PolicyManagers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Exceptions\RecursiveRelationshipException;
use Stancl\Tenancy\Exceptions\RLSCommentConstraintException;

// todo@samuel logical + structural refactor. the tree generation could use some dynamic programming optimizations
class TableRLSManager implements RLSPolicyManager
{
    public static bool $scopeByDefault = true;

    public function __construct(
        protected DatabaseManager $database
    ) {}

    public function generateQueries(array $trees = []): array
    {
        $queries = [];

        foreach ($trees ?: $this->shortestPaths() as $table => $path) {
            $queries[$table] = $this->generateQuery($table, $path);
        }

        return $queries;
    }

    /**
     * Reduce trees to shortest paths (structured like ['table_foo' => $shortestPathForFoo, 'table_bar' => $shortestPathForBar]).
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
    public function shortestPaths(array $trees = []): array
    {
        $reducedTrees = [];

        foreach ($trees ?: $this->generateTrees() as $table => $tree) {
            $reducedTrees[$table] = $this->findShortestPath($this->filterNonNullablePaths($tree) ?: $tree);
        }

        return $reducedTrees;
    }

    /**
     * Generate trees of paths that lead to the tenants table
     * for the foreign keys of all tables – only the paths that lead to the tenants table are included.
     *
     * Also unset the 'comment' key from the retrieved path steps.
     */
    public function generateTrees(): array
    {
        $trees = [];
        $builder = $this->database->getSchemaBuilder();

        // We loop through each table in the database
        foreach ($builder->getTableListing(schema: $this->database->getConfig('search_path')) as $table) {
            // E.g. "public.table_name" -> "table_name"
            $table = str($table)->afterLast('.')->toString();

            // For each table, we get a list of all foreign key columns
            $foreignKeys = collect($builder->getForeignKeys($table))->merge($this->getCommentConstraints($table))->map(function ($foreign) use ($table) {
                return $this->formatForeignKey($foreign, $table);
            });

            // We loop through each foreign key column and find
            // all possible paths that lead to the tenants table
            foreach ($foreignKeys as $foreign) {
                $paths = [];

                $this->generatePaths($table, $foreign, $paths);

                foreach ($paths as &$path) {
                    foreach ($path as &$step) {
                        unset($step['comment']);
                    }
                }

                if (count($paths)) {
                    $trees[$table][$foreign['foreignKey']] = $paths;
                }
            }
        }

        return $trees;
    }

    protected function generatePaths(string $table, array $foreign, array &$paths, array $currentPath = []): void
    {
        // If the foreign key has a comment of 'no-rls', we skip it
        // Also skip the foreign key if implicit scoping is off and the foreign key has no comment
        if ($this->shouldSkipPathLeadingThrough($foreign)) {
            return;
        }

        if (in_array($foreign['foreignTable'], array_column($currentPath, 'foreignTable'))) {
            throw new RecursiveRelationshipException;
        }

        $currentPath[] = $foreign;

        if ($foreign['foreignTable'] === tenancy()->model()->getTable()) {
            $paths[] = $currentPath;
        } else {
            // If not, recursively generate paths for the foreign table
            foreach (array_merge(
                $this->database->getSchemaBuilder()->getForeignKeys($foreign['foreignTable']),
                $this->getCommentConstraints($foreign['foreignTable'])
            ) as $nextConstraint) {
                $this->generatePaths($table, $this->formatForeignKey($nextConstraint, $foreign['foreignTable']), $paths, $currentPath);
            }
        }
    }

    protected function shouldSkipPathLeadingThrough(array $foreignKey): bool
    {
        // If the foreign key has a comment of 'no-rls', we skip it
        // Also skip the foreign key if implicit scoping is off and the foreign key has no comment
        $pathExplicitlySkipped = $foreignKey['comment'] === 'no-rls';
        $pathImplicitlySkipped = ! static::$scopeByDefault && (
            ! isset($foreignKey['comment']) ||
            (is_string($foreignKey['comment']) && ! Str::startsWith($foreignKey['comment'], 'rls'))
        );

        return $pathExplicitlySkipped || $pathImplicitlySkipped;
    }

    /**
     * Retrieve comment-based constraints for a table. These are columns with comments in the format:
     *   "rls <foreign_table>.<foreign_column>"
     *
     * Throws an exception if the comment is formatted incorrectly or if the referenced table/column does not exist.
     */
    protected function getCommentConstraints(string $tableName): array
    {
        $columns = $this->database->getSchemaBuilder()->getColumns($tableName);
        $schemaBuilder = $this->database->getSchemaBuilder();

        $commentConstraints = array_filter($columns, function ($column) {
            return (isset($column['comment']) && is_string($column['comment']))
                && Str::startsWith($column['comment'], 'rls ');
        });

        return array_map(function ($commentConstraint) use ($schemaBuilder, $tableName) {
            $comment = $commentConstraint['comment'];
            $constraintString = Str::after($comment, 'rls ');
            $constraint = explode('.', $constraintString);

            // Validate comment constraint format
            if (count($constraint) !== 2 || empty($constraint[0]) || empty($constraint[1])) {
                throw new RLSCommentConstraintException("Incorrectly formatted comment constraint on {$tableName}.{$commentConstraint['name']}: '{$comment}'");
            }

            $foreignTable = $constraint[0];
            $foreignColumn = $constraint[1];

            // Validate table existence
            $allTables = array_map(function ($table) {
                return str($table)->afterLast('.')->toString();
            }, $schemaBuilder->getTableListing(schema: $this->database->getConfig('search_path')));

            if (! in_array($foreignTable, $allTables, true)) {
                throw new RLSCommentConstraintException("Comment constraint on {$tableName}.{$commentConstraint['name']} references non-existent table '{$foreignTable}'");
            }

            // Validate column existence
            $foreignColumns = $schemaBuilder->getColumns($foreignTable);
            $foreignColumnNames = array_column($foreignColumns, 'name');
            if (! in_array($foreignColumn, $foreignColumnNames, true)) {
                throw new RLSCommentConstraintException("Comment constraint on {$tableName}.{$commentConstraint['name']} references non-existent column '{$foreignTable}.{$foreignColumn}'");
            }

            return [
                'foreign_table' => $foreignTable,
                'foreign_columns' => [$foreignColumn],
                'columns' => [$commentConstraint['name']],
            ];
        }, $commentConstraints);
    }

    /** Get tree's non-nullable paths. */
    protected function filterNonNullablePaths(array $tree): array
    {
        $nonNullablePaths = [];

        foreach ($tree as $foreignKey => $paths) {
            foreach ($paths as $path) {
                $pathIsNullable = false;

                foreach ($path as $step) {
                    if ($step['nullable']) {
                        $pathIsNullable = true;
                        break;
                    }
                }

                if (! $pathIsNullable) {
                    $nonNullablePaths[$foreignKey][] = $path;
                }
            }
        }

        return $nonNullablePaths;
    }

    /** Find the shortest path in a tree and unset the 'nullable' key from the path steps. */
    protected function findShortestPath(array $tree): array
    {
        $shortestPath = [];

        foreach ($tree as $pathsForForeignKey) {
            foreach ($pathsForForeignKey as $path) {
                if (empty($shortestPath) || count($shortestPath) > count($path)) {
                    $shortestPath = $path;

                    foreach ($shortestPath as &$step) {
                        unset($step['nullable']);
                    }
                }
            }
        }

        return $shortestPath;
    }

    /**
     * Formats the foreign key retrieved by Postgres or comment-based constraint to a more readable format.
     *
     * Also provides information about whether the foreign key is nullable,
     * and the foreign key column comment. These additional details are removed
     * from the foreign keys/path steps before returning the final shortest paths.
     *
     * The 'comment' key gets deleted while generating the full trees (in generateTrees()),
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
        // $foreignKey is one of the foreign keys retrieved by $this->database->getSchemaBuilder()->getForeignKeys($table)
        return [
            'foreignKey' => $foreignKeyName = $foreignKey['columns'][0],
            'foreignTable' => $foreignKey['foreign_table'],
            'foreignId' => $foreignKey['foreign_columns'][0],
            // Deleted in generateTrees()
            'comment' => $this->getComment($table, $foreignKeyName),
            // Deleted in shortestPaths()
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
}

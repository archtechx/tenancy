<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PDO;
use Stancl\Tenancy\Database\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Throwable;

class SQLiteDatabaseManager implements TenantDatabaseManager
{
    /**
     * SQLite database directory path.
     *
     * Defaults to database_path().
     */
    public static string|null $path = null;

    /*
     * If this isn't null, a connection to the tenant DB will be created
     * and passed to the provided closure, for the purpose of keeping the
     * connection alive for the desired lifetime. This means it's the
     * closure's job to store the connection in a place that lives as
     * long as the connection should live.
     *
     * The closure is called in makeConnectionConfig() -- a method normally
     * called shortly before a connection is established.
     *
     * NOTE: The closure is called EVERY time makeConnectionConfig()
     * is called, therefore it's up to the closure to discard
     * the connection if a connection to the same database is already persisted.
     *
     * The closure also receives the DSN used to create the PDO connection,
     * since the PDO connection driver makes it a bit hard to recover DB names
     * from PDO instances. That should make it easier to match these with
     * tenant instances passed to $closeInMemoryConnectionUsing closures,
     * if you're setting that property as well.
     *
     * @var Closure(PDO, string)|null
     */
    public static Closure|null $persistInMemoryConnectionUsing = null;

    /*
     * The opposite of $persistInMemoryConnectionUsing. This closure
     * is called when the tenant is deleted, to clear the database
     * in case a tenant with the same ID should be created within
     * the lifetime of the $persistInMemoryConnectionUsing logic.
     *
     * NOTE: The parameter provided to the closure is the Tenant
     * instance, not a PDO connection.
     *
     * @var Closure(Tenant)|null
     */
    public static Closure|null $closeInMemoryConnectionUsing = null;

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        /** @var TenantWithDatabase&Model $tenant */
        $name = $tenant->database()->getName();

        if ($this->isInMemory($name)) {
            // If :memory: is used, we update the tenant with a *named* in-memory SQLite connection.
            //
            // This makes use of the package feasible with in-memory SQLite. Pure :memory: isn't
            // sufficient since the tenant creation process involves constant creation and destruction
            // of the tenant connection, always clearing the memory (like migrations). Additionally,
            // tenancy()->central() calls would close the database since at the moment we close the
            // tenant connection (to prevent accidental references to it in the central context) when
            // tenancy is ended.
            //
            // Note that named in-memory databases DO NOT have process lifetime. You need an open
            // PDO connection to keep the memory from being cleaned up. It's up to the user how they
            // handle this, common solutions may involve storing the connection in the service container
            // or creating a closure holding a reference to it and passing that to register_shutdown_function().

            $name = '_tenancy_inmemory_' . $tenant->getTenantKey();
            $tenant->setInternal('db_name', "file:$name?mode=memory&cache=shared");
            $tenant->save();

            return true;
        }

        return file_put_contents($this->getPath($name), '') !== false;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();

        if ($this->isInMemory($name)) {
            if (static::$closeInMemoryConnectionUsing) {
                (static::$closeInMemoryConnectionUsing)($tenant);
            }

            return true;
        }

        $path = $this->getPath($name);

        try {
            unlink($path . '-journal');
            unlink($path . '-wal');
            unlink($path . '-shm');
        } catch (Throwable) {}

        try {
            return unlink($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseExists(string $name): bool
    {
        return $this->isInMemory($name) || file_exists($this->getPath($name));
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        if ($this->isInMemory($databaseName)) {
            $baseConfig['database'] = $databaseName;

            if (static::$persistInMemoryConnectionUsing !== null) {
                $dsn = "sqlite:$databaseName";
                (static::$persistInMemoryConnectionUsing)(new PDO($dsn), $dsn);
            }
        } else {
            $baseConfig['database'] = database_path($databaseName);
        }

        return $baseConfig;
    }

    public function getPath(string $name): string
    {
        if (static::$path) {
            return rtrim(static::$path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        }

        return database_path($name);
    }

    public static function isInMemory(string $name): bool
    {
        return $name === ':memory:' || str_contains($name, '_tenancy_inmemory_');
    }
}

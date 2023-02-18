<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Closure;
use Illuminate\Database;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase as Tenant;
use Stancl\Tenancy\Database\Exceptions\DatabaseManagerNotRegisteredException;
use Stancl\Tenancy\Database\Exceptions\NoConnectionSetException;

class DatabaseConfig
{
    /** The tenant whose database we're dealing with. */
    public Tenant&Model $tenant;

    /** Database username generator (can be set by the developer.) */
    public static Closure|null $usernameGenerator = null;

    /** Database password generator (can be set by the developer.) */
    public static Closure|null $passwordGenerator = null;

    /** Database name generator (can be set by the developer.) */
    public static Closure|null $databaseNameGenerator = null;

    public static function __constructStatic(): void
    {
        static::$usernameGenerator = static::$usernameGenerator ?? function (Model&Tenant $tenant) {
            return Str::random(16);
        };

        static::$passwordGenerator = static::$passwordGenerator ?? function (Model&Tenant $tenant) {
            return Hash::make(Str::random(32));
        };

        static::$databaseNameGenerator = static::$databaseNameGenerator ?? function (Model&Tenant $tenant) {
            return config('tenancy.database.prefix') . $tenant->getTenantKey() . config('tenancy.database.suffix');
        };
    }

    public function __construct(Model&Tenant $tenant)
    {
        static::__constructStatic();

        $this->tenant = $tenant;
    }

    public static function generateDatabaseNamesUsing(Closure $databaseNameGenerator): void
    {
        static::$databaseNameGenerator = $databaseNameGenerator;
    }

    public static function generateUsernamesUsing(Closure $usernameGenerator): void
    {
        static::$usernameGenerator = $usernameGenerator;
    }

    public static function generatePasswordsUsing(Closure $passwordGenerator): void
    {
        static::$passwordGenerator = $passwordGenerator;
    }

    public function getName(): string
    {
        return $this->tenant->getInternal('db_name') ?? (static::$databaseNameGenerator)($this->tenant);
    }

    public function getUsername(): ?string
    {
        return $this->tenant->getInternal('db_username') ?? null;
    }

    public function getPassword(): ?string
    {
        return $this->tenant->getInternal('db_password') ?? null;
    }

    /**
     * Generate DB name, username & password and write them to the tenant model.
     */
    public function makeCredentials(): void
    {
        $this->tenant->setInternal('db_name', $this->getName());

        if ($this->connectionDriverManager($this->getTemplateConnectionDriver()) instanceof Contracts\ManagesDatabaseUsers) {
            $this->tenant->setInternal('db_username', $this->getUsername() ?? (static::$usernameGenerator)($this->tenant));
            $this->tenant->setInternal('db_password', $this->getPassword() ?? (static::$passwordGenerator)($this->tenant));
        }

        if ($this->tenant->exists) {
            $this->tenant->save();
        }
    }

    public function getTemplateConnectionDriver(): string
    {
        return $this->getTemplateConnection()['driver'];
    }

    public function getTemplateConnection(): array
    {
        if ($template = $this->tenant->getInternal('db_connection')) {
            return config("database.connections.{$template}");
        }

        if ($template = config('tenancy.database.template_tenant_connection')) {
            return is_array($template) ? array_merge($this->getCentralConnection(), $template) : config("database.connections.{$template}");
        }

        return $this->getCentralConnection();
    }

    protected function getCentralConnection(): array
    {
        $centralConnectionName = config('tenancy.database.central_connection');

        return config("database.connections.{$centralConnectionName}");
    }

    public function getTenantHostConnectionName(): string
    {
        return config('tenancy.database.tenant_host_connection_name', 'tenant_host_connection');
    }

    /**
     * Tenant's own database connection config.
     */
    public function connection(): array
    {
        $templateConnection = $this->getTemplateConnection();

        return $this->manager()->makeConnectionConfig(
            array_merge($templateConnection, $this->tenantConfig()),
            $this->getName()
        );
    }

    /**
     * Tenant's host database connection config.
     */
    public function hostConnection(): array
    {
        $config = $this->tenantConfig();
        $templateConnection = $this->getTemplateConnection();

        if ($this->connectionDriverManager($this->getTemplateConnectionDriver()) instanceof Contracts\ManagesDatabaseUsers) {
            // We're removing the username and password because user with these credentials is not created yet
            // If you need to provide username and password when using PermissionControlledMySQLDatabaseManager,
            // consider creating a new connection and use it as `tenancy_db_connection` tenant config key
            unset($config['username'], $config['password']);
        }

        if (! $config) {
            return $templateConnection;
        }

        return array_replace($templateConnection, $config);
    }

    /**
     * Purge host database connection.
     *
     * It's possible database has previous tenant connection.
     * This will clean up the previous connection before creating it for the current tenant.
     */
    public function purgeHostConnection(): void
    {
        DB::purge($this->getTenantHostConnectionName());
    }

    /**
     * Additional config for the database connection, specific to this tenant.
     */
    public function tenantConfig(): array
    {
        $dbConfig = array_filter(array_keys($this->tenant->getAttributes()), function ($key) {
            return Str::startsWith($key, $this->tenant->internalPrefix() . 'db_');
        });

        // Remove DB name because we set that separately
        if (($pos = array_search($this->tenant->internalPrefix() . 'db_name', $dbConfig)) !== false) {
            unset($dbConfig[$pos]);
        }

        // Remove DB connection because that's not used inside the array
        if (($pos = array_search($this->tenant->internalPrefix() . 'db_connection', $dbConfig)) !== false) {
            unset($dbConfig[$pos]);
        }

        return array_reduce($dbConfig, function ($config, $key) {
            return array_merge($config, [
                Str::substr($key, strlen($this->tenant->internalPrefix() . 'db_')) => $this->tenant->getAttribute($key),
            ]);
        }, []);
    }

    /** Get the TenantDatabaseManager for this tenant's connection.
     *
     * @throws NoConnectionSetException|DatabaseManagerNotRegisteredException
     */
    public function manager(): Contracts\TenantDatabaseManager
    {
        // Laravel caches the previous PDO connection, so we purge it to be able to change the connection details
        $this->purgeHostConnection(); // todo come up with a better name

        // Create the tenant host connection config
        $tenantHostConnectionName = $this->getTenantHostConnectionName();
        config(["database.connections.{$tenantHostConnectionName}" => $this->hostConnection()]);

        $manager = $this->connectionDriverManager(config("database.connections.{$tenantHostConnectionName}.driver"));

        if ($manager instanceof Contracts\StatefulTenantDatabaseManager) {
            $manager->setConnection($tenantHostConnectionName);
        }

        return $manager;
    }

    /**
     * todo come up with a better name
     * Get database manager class from the given connection config's driver.
     *
     * @throws DatabaseManagerNotRegisteredException
     */
    protected function connectionDriverManager(string $driver): Contracts\TenantDatabaseManager
    {
        $databaseManagers = config('tenancy.database.managers');

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new Exceptions\DatabaseManagerNotRegisteredException($driver);
        }

        return app($databaseManagers[$driver]);
    }
}

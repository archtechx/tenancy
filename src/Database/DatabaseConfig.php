<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Closure;
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

    /** Additional config for the database connection, specific to this tenant. */
    public array $tenantConfig;

    /**
     * Database username generator (can be set by the developer.).
     *
     * @var Closure(Model&Tenant, self): string
     */
    public static Closure $usernameGenerator;

    /**
     * Database password generator (can be set by the developer.).
     *
     * @var Closure(Model&Tenant, self): string
     */
    public static Closure $passwordGenerator;

    /**
     * Database name generator (can be set by the developer.).
     *
     * @var Closure(Model&Tenant, self): string
     */
    public static Closure $databaseNameGenerator;

    public static function __constructStatic(): void
    {
        if (! isset(static::$usernameGenerator)) {
            static::$usernameGenerator = function () {
                return Str::random(16);
            };
        }

        if (! isset(static::$passwordGenerator)) {
            static::$passwordGenerator = function () {
                return Hash::make(Str::random(32));
            };
        }

        if (! isset(static::$databaseNameGenerator)) {
            static::$databaseNameGenerator = function (Model&Tenant $tenant, self $self) {
                $suffix = config('tenancy.database.suffix');

                if (! $suffix && $self->getTemplateConnection()['driver'] === 'sqlite') {
                    $suffix = '.sqlite';
                }

                return config('tenancy.database.prefix') . $tenant->getTenantKey() . $suffix;
            };
        }
    }

    public function __construct(Model&Tenant $tenant, array $databaseConfig)
    {
        static::__constructStatic();

        $this->tenant = $tenant;
        $this->tenantConfig = $databaseConfig;
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
        return $this->tenant->getInternal('db_name') ?? (static::$databaseNameGenerator)($this->tenant, $this);
    }

    public function getUsername(): ?string
    {
        return $this->tenant->getInternal('db_username');
    }

    public function getPassword(): ?string
    {
        return $this->tenant->getInternal('db_password');
    }

    /**
     * Generate DB name, username & password and write them to the tenant model.
     */
    public function makeCredentials(): void
    {
        $this->tenant->setInternal('db_name', $this->getName());

        if ($this->managerForDriver($this->getTemplateConnectionDriver()) instanceof Contracts\ManagesDatabaseUsers) {
            $this->tenant->setInternal('db_username', $this->getUsername() ?? (static::$usernameGenerator)($this->tenant, $this));
            $this->tenant->setInternal('db_password', $this->getPassword() ?? (static::$passwordGenerator)($this->tenant, $this));
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
            return is_array($template)
                ? array_merge($this->getCentralConnection(), $template)
                : config("database.connections.{$template}");
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
            array_merge($templateConnection, $this->tenantConfig),
            $this->getName()
        );
    }

    /**
     * Tenant's host database connection config.
     */
    public function hostConnection(): array
    {
        $config = $this->tenantConfig;
        $templateConnection = $this->getTemplateConnection();

        if ($this->managerForDriver($this->getTemplateConnectionDriver()) instanceof Contracts\ManagesDatabaseUsers) {
            // We remove the username and password because the user with these credentials is not yet created.
            // If you need to provide a username and a password when using a permission controlled database manager,
            // consider creating a new connection and use it as `tenancy_db_connection`.
            unset($config['username'], $config['password']);
        }

        if (! $config) {
            return $templateConnection;
        }

        return array_replace($templateConnection, $config);
    }

    /**
     * Purge the previous host connection before opening it for another tenant.
     */
    public function purgeHostConnection(): void
    {
        DB::purge($this->getTenantHostConnectionName());
    }

    /**
     * Get the TenantDatabaseManager for this tenant's host connection.
     *
     * @throws NoConnectionSetException|DatabaseManagerNotRegisteredException
     */
    public function manager(): Contracts\TenantDatabaseManager
    {
        // Laravel persists the PDO connection, so we purge it to be able to change the connection details
        $this->purgeHostConnection();

        // Create the tenant host connection config
        $tenantHostConnectionName = $this->getTenantHostConnectionName();
        config(["database.connections.{$tenantHostConnectionName}" => $this->hostConnection()]);

        $manager = $this->managerForDriver(config("database.connections.{$tenantHostConnectionName}.driver"));

        if ($manager instanceof Contracts\StatefulTenantDatabaseManager) {
            $manager->setConnection($tenantHostConnectionName);
        }

        return $manager;
    }

    /**
     * Get the TenantDatabaseManager for a given database driver.
     *
     * @throws DatabaseManagerNotRegisteredException
     */
    protected function managerForDriver(string $driver): Contracts\TenantDatabaseManager
    {
        $databaseManagers = config('tenancy.database.managers');

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException($driver);
        }

        return app($databaseManagers[$driver]);
    }
}

<?php

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;

class DatabaseConfig
{ 
    /** @var Tenant */
    public $tenant;

    /** @var callable */
    public static $usernameGenerator;

    /** @var callable */
    public static $passwordGenerator;

    /** @var callable */
    public static $databaseNameGenerator;

    public static function __constructStatic(): void
    {
        static::$usernameGenerator = static::$usernameGenerator ?? function (Tenant $tenant) {
            return Str::random(16);
        };

        static::$passwordGenerator = static::$usernameGenerator ?? function (Tenant $tenant) {
            return Hash::make(Str::random(32));
        };

        static::$databaseNameGenerator = static::$databaseNameGenerator ?? function (Tenant $tenant) {
            return config('tenancy.database.prefix') . $tenant->id . config('tenancy.database.suffix');
        };
    }

    public function __construct(Tenant $tenant)
    {
        static::__constructStatic();

        $this->tenant = $tenant;
    }

    public static function generateDatabaseNameUsing(callable $databaseNameGenerator): void
    {
        static::$databaseNameGenerator = $databaseNameGenerator;
    }

    public static function generateUsernameUsing(callable $usernameGenerator): void
    {
        static::$usernameGenerator = $usernameGenerator;
    }

    public static function generatePasswordUsing(callable $passwordGenerator): void
    {
        static::$passwordGenerator = $passwordGenerator;
    }

    public function getName(): string
    {
        return $this->tenant->data['_tenancy_db_name'];
    }

    public function getUsername(): string
    {
        return $this->tenant->data['_tenancy_db_username'];
    }

    public function getPassword(): string
    {
        return $this->tenant->data['_tenancy_db_password'];
    }

    public function getTemplateDatabaseConnection(): string
    {
        return $this->tenant->data['_tenancy_db_connection'];
    }

    public function makeCredentials(): void
    {
        $this->tenant->data['_tenancy_db_name'] = $this->getName() ?? (static::$databaseNameGenerator)($this->tenant);

        if ($this->manager() instanceof ManagesDatabaseUsers) {
            $this->tenant->data['_tenancy_db_username'] = $this->getUsername() ?? (static::$usernameGenerator)($this->tenant);
            $this->tenant->data['_tenancy_db_password'] = $this->getPassword() ?? (static::$passwordGenerator)($this->tenant);
        }

        $this->tenant->save();
    }

    /**
     * Template used to construct the tenant connection. Also serves as
     * the root connection on which the tenant database is created.
     */
    public function getTemplateConnectionName()
    {
        $name = $this->tenant->getTemplateDatabaseConnection();

        // If we're using e.g. 'tenant', the default, template connection
        // and it doesn't exist, we'll go for the default DB template.
        if (! array_key_exists($name, config('database.connections'))) {
            $name = config('tenancy.database.template_connection') ?? DatabaseManager::$originalDefaultConnectionName;
        };

        return $name;
    }

    /**
     * Tenant's own database connection config.
     */
    public function connection(): array
    {
        $templateConnection = config("database.connections.{$this->getTemplateConnectionName()}");

        return array_merge($templateConnection, $this->tenantConfig(), [
            $this->manager()->getSeparator() => $this->tenant->database()->getName(),
        ]);
    }

    /**
     * Additional config for the database connection, specific to this tenant.
     */
    public function tenantConfig(): array
    {
        $dbConfig = array_filter(array_keys($this->tenant->data), function ($key) {
            return Str::startsWith($key, '_tenancy_db_');
        });

        // Remove DB name because we set that separately
        if (isset($dbConfig['_tenancy_db_name'])) {
            unset($dbConfig['_tenancy_db_name']);
        }

        return array_reduce($dbConfig, function ($config, $key) {
            return array_merge($config, [
                Str::substr($key, 0, strlen('_tenancy_db_')) => $this->tenant[$key],
            ]);
        }, []);
    }

    /**
     * Get the TenantDatabaseManager for this tenant's connection.
     */
    public function manager(): TenantDatabaseManager
    {
        $driver = config("database.connections.{$this->getTemplateConnectionName()}.driver");

        $databaseManagers = config('tenancy.database_managers');

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException($driver);
        }

        /** @var TenantDatabaseManager $databaseManager */
        $databaseManager = app($databaseManagers[$driver]);

        if ($databaseManager instanceof CanSetConnection) {
            $databaseManager->setConnection($this->getTemplateConnectionName());
        }

        return $databaseManager;
    }
}

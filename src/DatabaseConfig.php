<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Contracts\ModifiesDatabaseNameForConnection;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Database\Models\Tenant;
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

    public static function generateDatabaseNamesUsing(callable $databaseNameGenerator): void
    {
        static::$databaseNameGenerator = $databaseNameGenerator;
    }

    public static function generateUsernamesUsing(callable $usernameGenerator): void
    {
        static::$usernameGenerator = $usernameGenerator;
    }

    public static function generatePasswordsUsing(callable $passwordGenerator): void
    {
        static::$passwordGenerator = $passwordGenerator;
    }

    public function getName(): ?string
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

    public function makeCredentials(): void
    {
        $this->tenant->setInternal('db_name', $this->getName() ?? (static::$databaseNameGenerator)($this->tenant));

        if ($this->manager() instanceof ManagesDatabaseUsers) {
            $this->tenant->setInternal('db_username', $this->getUsername() ?? (static::$usernameGenerator)($this->tenant));
            $this->tenant->setInternal('db_password', $this->getPassword() ?? (static::$passwordGenerator)($this->tenant));
        }
    }

    public function getTemplateConnectionName(): string
    {
        return $this->tenant->getInternal('db_connection')
            ?? config('tenancy.template_tenant_connection')
            ?? DatabaseManager::$originalDefaultConnectionName;
    }

    /**
     * Tenant's own database connection config.
     */
    public function connection(): array
    {
        $template = $this->getTemplateConnectionName();

        $templateConnection = config("database.connections.{$template}");

        $databaseName = $this->getName();
        if (($manager = $this->manager()) instanceof ModifiesDatabaseNameForConnection) {
            /** @var ModifiesDatabaseNameForConnection $manager */
            $databaseName = $manager->getDatabaseNameForConnection($databaseName);
        }

        return array_merge($templateConnection, $this->tenantConfig(), [
            $this->manager()->getSeparator() => $databaseName,
        ]);
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

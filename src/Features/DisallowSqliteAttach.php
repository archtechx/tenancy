<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use Stancl\Tenancy\Contracts\Feature;

class DisallowSqliteAttach implements Feature
{
    public static string|false|null $extensionPath = null;

    public function bootstrap(): void
    {
        // Handle any already resolved connections
        foreach (DB::getConnections() as $connection) {
            if ($connection instanceof SQLiteConnection) {
                if (! $this->setAuthorizer($connection->getPdo())) {
                    return;
                }
            }
        }

        // Apply the change to all sqlite connections resolved in the future
        DB::extend('sqlite', function ($config, $name) {
            $conn = app(ConnectionFactory::class)->make($config, $name);
            $this->setAuthorizer($conn->getPdo());

            return $conn;
        });
    }

    protected function setAuthorizer(PDO $pdo): bool
    {
        if (PHP_VERSION_ID >= 80500) {
            $this->setNativeAuthorizer($pdo);

            return true;
        }

        static $loadExtensionSupported = method_exists($pdo, 'loadExtension');

        if ((! $loadExtensionSupported) ||
            (static::$extensionPath === false) ||
            (PHP_INT_SIZE !== 8)
        ) return false;

        $suffix = match (PHP_OS_FAMILY) {
            'Linux' => 'so',
            'Windows' => 'dll',
            'Darwin' => 'dylib',
            default => 'error',
        };

        if ($suffix === 'error') return false;

        $arch = php_uname('m');
        $arm = $arch === 'aarch64' || $arch === 'arm64';

        static::$extensionPath ??= realpath(base_path('vendor/stancl/tenancy/extensions/lib/' . ($arm ? 'arm/' : '') . 'noattach.' . $suffix));
        if (static::$extensionPath === false) return false;

        $pdo->loadExtension(static::$extensionPath); // @phpstan-ignore method.notFound

        return true;
    }

    protected function setNativeAuthorizer(PDO $pdo): void
    {
        // @phpstan-ignore method.notFound
        $pdo->setAuthorizer(static function (int $action): int {
            return $action === 24 // SQLITE_ATTACH
                ? PDO\Sqlite::DENY
                : PDO\Sqlite::OK;
        });
    }
}

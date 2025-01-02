<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Exception;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenancy;

class DisallowSqliteAttach implements Feature
{
    protected static bool|null $loadExtensionSupported = null;
    public static string|false|null $extensionPath = null;

    public function bootstrap(Tenancy $tenancy): void
    {
        // Handle any already resolved connections
        foreach (DB::getConnections() as $connection) {
            if ($connection instanceof SQLiteConnection) {
                if (! $this->loadExtension($connection->getPdo())) {
                    return;
                }
            }
        }

        // Apply the change to all sqlite connections resolved in the future
        DB::extend('sqlite', function ($config, $name) {
            $conn = app(ConnectionFactory::class)->make($config, $name);
            $this->loadExtension($conn->getPdo());

            return $conn;
        });
    }

    protected function loadExtension(PDO $pdo): bool
    {
        if (static::$loadExtensionSupported === null) {
            static::$loadExtensionSupported = method_exists($pdo, 'loadExtension');
        }

        if (static::$loadExtensionSupported === false) {
            return false;
        }

        $suffix = match (PHP_OS_FAMILY) {
            'Linux' => '.so',
            'Windows' => '.dll',
            'Darwin' => '.dylib',
            default => throw new Exception("The DisallowSqliteAttach feature doesn't support your operating system: " . PHP_OS_FAMILY),
        };

        $arch = php_uname('m');
        $arm = $arch === 'aarch64' || $arch === 'arm64';

        static::$extensionPath ??= realpath(base_path('vendor/stancl/tenancy/src/extensions/lib/' . ($arm ? 'arm/' : '') . 'noattach' . $suffix));
        if (static::$extensionPath === false) {
            return false;
        }

        $pdo->loadExtension(static::$extensionPath);

        return true;
    }
}

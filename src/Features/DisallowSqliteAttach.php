<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Sqlite;
use Stancl\Tenancy\Contracts\Feature;

class DisallowSqliteAttach implements Feature
{
    public function bootstrap(): void
    {
        // Handle any already resolved connections
        foreach (DB::getConnections() as $connection) {
            if ($connection instanceof SQLiteConnection) {
                $this->setAuthorizer($connection->getPdo());
            }
        }

        // Apply the change to all sqlite connections resolved in the future
        DB::extend('sqlite', function ($config, $name) {
            $conn = app(ConnectionFactory::class)->make($config, $name);
            $this->setAuthorizer($conn->getPdo());

            return $conn;
        });
    }

    protected function setAuthorizer(PDO $pdo): void
    {
        assert($pdo instanceof Sqlite);

        $pdo->setAuthorizer(static function (int $action): int {
            return $action === 24 // SQLITE_ATTACH
                ? Sqlite::DENY
                : Sqlite::OK;
        });
    }
}

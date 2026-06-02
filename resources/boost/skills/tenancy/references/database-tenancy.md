# Database Tenancy Reference

Use this when changing tenant database isolation or managers.

## Source Files

- `src/Database/DatabaseManager.php`
- `src/Database/DatabaseConfig.php`
- `src/Database/TenantDatabaseManagers/*`
- `src/Bootstrappers/DatabaseTenancyBootstrapper.php`
- `assets/config.php`

## Supported Isolation

- Separate tenant databases.
- PostgreSQL schema isolation.
- Permission-controlled tenant database users.
- PostgreSQL RLS for single-database tenancy.

## Managers

- SQLite: `SQLiteDatabaseManager`
- MySQL/MariaDB: `MySQLDatabaseManager`
- PostgreSQL: `PostgreSQLDatabaseManager`
- SQL Server: `MicrosoftSQLDatabaseManager`
- Permission-controlled variants for MySQL, PostgreSQL, SQL Server.
- PostgreSQL schema managers for schema isolation.

## Rules

- `tenant` is a reserved dynamic connection name.
- Use `database.template_tenant_connection` for the tenant connection template.
- Use `database.tenant_host_connection_name` for database creation/deletion host connection.
- Tenant DB names are `prefix + tenant_id + suffix`.
- Use schema managers only when PostgreSQL schema isolation is intended.
- Test creation, migration, rollback, deletion, and connection restoration.

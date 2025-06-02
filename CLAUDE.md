# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Testing
- `composer test` - Run tests without coverage using Docker
- `./test tests/TestFile.php` - Run an entire test file
- `./t 'test name'` - Run a specific test
- You can append `-v` to get a full stack trace if a test fails due to an exception

### Code Quality
- `composer phpstan` - Run PHPStan static analysis (level 8)
- `composer cs` - Fix code style using PHP CS Fixer

### Docker Development
- `composer docker-up` - Start Docker environment
- `composer docker-down` - Stop Docker environment
- `composer docker-restart` - Restart Docker environment

## Architecture Overview

**Tenancy for Laravel** is a multi-tenancy package that automatically handles tenant isolation without requiring changes to application code.

### Core Components

**Central Classes:**
- `Tenancy` - Main orchestrator class managing tenant context and lifecycle
- `TenancyServiceProvider` (NOT the stub) - Registers services, commands, and bootstrappers
- `Tenant` (model) - Represents individual tenants with domains and databases
- `Domain` (model) - Maps domains/subdomains to tenants

**Tenant Identification:**
- **Resolvers** (`src/Resolvers/`) - Identify tenants by domain, path, or request data - this data comes from middleware
- **Middleware** (`src/Middleware/`) - Middleware that calls resolvers and tries to initialize tenancy based on information from a request
- **Cached resolvers** - Cached wrapper around resolvers to avoid querying the central database

**Tenancy Bootstrappers (`src/Bootstrappers/`):**
- `DatabaseTenancyBootstrapper` - Switches database connections
- `CacheTenancyBootstrapper` - Isolates cache by tenant
- `FilesystemTenancyBootstrapper` - Manages tenant-specific storage
- `QueueTenancyBootstrapper` - Ensures queued jobs run in correct tenant context
- `RedisTenancyBootstrapper` - Prefixes Redis keys by tenant

**Database Management:**
- **DatabaseManager** - Creates/deletes tenant databases and users
- **TenantDatabaseManagers** - Database-specific implementations (MySQL, PostgreSQL, SQLite, SQL Server)
- **Row Level Security (RLS)** - PostgreSQL-based tenant isolation using policies

**Advanced Features:**
- **Resource Syncing** - Sync central models to tenant databases
- **User Impersonation** - Admin access to tenant contexts
- **Cross-domain redirects** - Handle multi-domain tenant setups
- **Telescope integration** - Tag entries by tenant

### Key Patterns

**Tenant Context Management:**
```php
tenancy()->initialize($tenant);           // Switch to tenant
tenancy()->run($tenant, $callback);      // Atomic tenant execution
tenancy()->runForMultiple($tenants, $callback); // Batch operations
tenancy()->central($callback);           // Run in central context
```

**Tenant Identification Flow:**
1. Middleware identifies tenant from request (domain/subdomain/path)
2. Resolver fetches tenant model from identification data
3. Tenancy initializes and bootstrappers configure tenant context
4. Application runs with tenant-specific database/cache/storage

**Route Middleware Groups:**
All of these work as flags, i.e. middleware groups that are empty arrays with a purely semantic use.
- `tenant` - Routes requiring tenant context
- `central` - Routes for central/admin functionality
- `universal` - Routes working in both contexts
- `clone` - Tells route cloning logic to clone the route

### Early Identification

**Early identification** ensures tenancy is initialized before controller instantiation, which is critical for certain scenarios.

**When needed:**
- Controllers using constructor dependency injection
- Integration with packages that inject dependencies in constructors

**The Problem:**
Laravel executes controller constructors and route model binding before route-level middleware runs, causing services to use central context instead of tenant context.

**Solutions:**
1. **Avoid Constructor Injection** - Use method injection instead
2. **Laravel's Native Solution** - Use controllers that implement `HasMiddleware` interface
3. **Kernel Identification** - Add middleware to HTTP Kernel's global stack:

```php
// In HttpKernel.php
protected $middleware = [
    \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
    // other middleware...
];
```

Note you also need to flag the route with the `'tenant'` middleware if default route mode (set in config) isn't set to TENANT.

**Benefits:**
- Constructor dependency injection receives tenant-aware services
- Seamless integration with existing Laravel applications

### Testing Environment

Tests use Docker with MySQL/PostgreSQL/Redis. The `./test` script runs Pest tests inside containers with proper database isolation.

`./t 'test name'` is equivalent to `./test --filter 'test name'`

**Key test patterns:**
- Database preparation and cleanup between tests
- Multi-database scenarios (central + tenant databases)
- Middleware and identification testing
- Resource syncing validation

### Configuration

Central config in `config/tenancy.php` controls:
- Tenant/domain model classes
- Database connection settings
- Enabled bootstrappers and features
- Identification middleware and resolvers
- Cache and storage prefixes

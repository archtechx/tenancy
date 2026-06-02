# Models And Domains Reference

Use this when changing tenant/domain models or tenant-owned models.

## Source Files

- `src/Database/Models/Tenant.php`
- `src/Database/Models/Domain.php`
- `src/Contracts/Tenant.php`
- `src/Contracts/Domain.php`
- `src/Database/Concerns/*`
- `assets/migrations/*`

## Default Tenant Model

- Table: `tenants`
- Primary key: `id`
- Uses `VirtualColumn`, `CentralConnection`, `GeneratesIds`, `HasInternalKeys`, `TenantRun`, `InitializationHelpers`, `InvalidatesResolverCache`.
- Dispatches tenant lifecycle events.

## Default Domain Model

- Unique `domain` column.
- Belongs to configured tenant model using `Tenancy::tenantKeyColumn()`.
- Uses `CentralConnection`, `EnsuresDomainIsNotOccupied`, `ConvertsDomainsToLowercase`, `InvalidatesTenantsResolverCache`.

## Single-Database Traits

- `BelongsToTenant`
- `FillsCurrentTenant`
- `TenantConnection`
- `CentralConnection`
- `HasScopedValidationRules`
- `RLSModel`

## Rules

- Custom tenant models must implement `Stancl\Tenancy\Contracts\Tenant`.
- Custom domain models must implement `Stancl\Tenancy\Contracts\Domain`.
- Preserve resolver cache invalidation behavior when replacing models.
- Use tenant scoping traits consistently for single-database tenancy.
- If auto-increment tenant IDs are used, update config and migrations together.

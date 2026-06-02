# Tenant Identification Reference

Use this when resolving tenants from requests.

## Source Files

- `src/Middleware/IdentificationMiddleware.php`
- `src/Middleware/InitializeTenancyByDomain.php`
- `src/Middleware/InitializeTenancyBySubdomain.php`
- `src/Middleware/InitializeTenancyByDomainOrSubdomain.php`
- `src/Middleware/InitializeTenancyByPath.php`
- `src/Middleware/InitializeTenancyByRequestData.php`
- `src/Middleware/InitializeTenancyByOriginHeader.php`
- `src/Middleware/PreventAccessFromUnwantedDomains.php`
- `src/Middleware/ScopeSessions.php`
- `src/Resolvers/*`

## Middleware

- `InitializeTenancyByDomain`
- `InitializeTenancyBySubdomain`
- `InitializeTenancyByDomainOrSubdomain`
- `InitializeTenancyByPath`
- `InitializeTenancyByRequestData`
- `InitializeTenancyByOriginHeader`
- `PreventAccessFromUnwantedDomains`
- `CheckTenantForMaintenanceMode`
- `ScopeSessions`

## Resolver Config

- `DomainTenantResolver`: cache, TTL, cache store.
- `PathTenantResolver`: tenant route parameter, route name prefix, tenant model column, allowed extra columns, cache.
- `RequestDataTenantResolver`: header, cookie, query parameter, tenant model column, cache.

## Rules

- Configure `identification.central_domains` for domain/subdomain strategies.
- Use `PreventAccessFromUnwantedDomains` only with configured domain identification middleware.
- For path identification, confirm the tenant parameter name before defining URLs.
- For request-data identification, set unused channels to `null`.
- If custom middleware is introduced, add it to the appropriate config category.
- Test success and failure identification paths.

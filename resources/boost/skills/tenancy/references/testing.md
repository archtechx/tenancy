# Testing Reference

Use this when adding or reviewing tenancy behavior tests.

## Source Files

- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/*`

## High-Value Test Areas

- Installation and published files.
- Central route access.
- Tenant route access.
- Domain, subdomain, path, request data, and origin header identification.
- Identification failures.
- Tenant context API restoration after success and exceptions.
- Database connection switching and reverting.
- Cache, Redis, filesystem, session, queue, URL, mail, and broadcasting scoping.
- Tenant migrations, rollbacks, seeds, and tenant command options.
- Tenant lifecycle jobs and event pipelines.
- Resource syncing.
- User impersonation.
- Pending tenants.
- RLS policies.
- Optional features.

## Useful Existing Tests

- `tests/AutomaticModeTest.php`
- `tests/ManualModeTest.php`
- `tests/RouteMiddlewareTest.php`
- `tests/PathIdentificationTest.php`
- `tests/RequestDataIdentificationTest.php`
- `tests/OriginHeaderIdentificationTest.php`
- `tests/TenantAssetTest.php`
- `tests/CommandsTest.php`
- `tests/QueueTest.php`
- `tests/SingleDatabaseTenancyTest.php`
- `tests/RLS/*`
- `tests/ResourceSyncingTest.php`
- `tests/TenantUserImpersonationTest.php`

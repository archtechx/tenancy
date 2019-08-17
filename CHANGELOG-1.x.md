# Release Notes for 1.x

## [v1.7.0 (2019-08-17)](https://github.com/stancl/tenancy/compare/v1.6.1...v1.7.0)

### Added:

- DB storage driver - you don't have to use Redis to store tenants anymore. Relational databases are now supported as well. [more info](https://stancl-tenancy.netlify.com/docs/storage-drivers/#database)
- `tenancy:install` will do everything except DB/Redis connection creation for you. It will make changes to Http/Kernel.php, create `routes/tenant.php`, publish config, and (optionally) publish the migration. [more info](https://stancl-tenancy.netlify.com/docs/installation/)
- `tenants:run` [more info](https://stancl-tenancy.netlify.com/docs/console-commands/#run)
- New documentation: https://stancl-tenancy.netlify.com
- Custom tenant DB names [more info](https://stancl-tenancy.netlify.com/docs/custom-database-names/)
- stancl/tenancy events [more info](https://stancl-tenancy.netlify.com/docs/event-system/)

### Fixed:

- #89 *Command "tenants:migrate" cannot be found when used in app code*
- #87 *Unable to migrate multiple tenants at once when using MySQL*
- #96 *Issue w/ redis->scan() in getAllTenants logic.*

## [v1.6.1 (2019-08-04)](https://github.com/stancl/tenancy/compare/v1.6.0...v1.6.1)

Multiple phpunit.xml configs are now generated to run the tests with different configurations, such as different Redis drivers.

### Fixed

- `tenancy()->all()` with predis [`0dc8c80`](https://github.com/stancl/tenancy/commit/0dc8c80a02efbee5676cc72e648e108037ca5268)

### Dropped

- Laravel 5.7 support [`65b3882`](https://github.com/stancl/tenancy/commit/65b38827d5a2fa183838a9dce9fb6a157fd7e859)

## [v1.6.0 (2019-07-30)](https://github.com/stancl/tenancy/compare/v1.5.1...v1.6.0)

### Added

- `GlobalCache` facade [#78](https://github.com/stancl/tenancy/pull/78)

## [v1.5.1 (2019-07-25)](https://github.com/stancl/tenancy/compare/v1.5.0...v1.5.1)

### Fixed

- Database is reconnected after migrating/rolling back/seeding is done [#71](https://github.com/stancl/tenancy/pull/71)
- Fixed tenant()->delete() (it used to delete the record from the `tenants` namespace but not the `domains` namespace) [#73](https://github.com/stancl/tenancy/pull/73)

## [v1.5.0 (2019-07-13)](https://github.com/stancl/tenancy/compare/v1.4.0...v1.5.0)

### Added

- PostgreSQL DB manager [#52](https://github.com/stancl/tenancy/pull/52)
- `tenancy()->end()` [#68](https://github.com/stancl/tenancy/pull/68)

### Fixed

- Return type docblock for `TenantManager::all()` [#63](https://github.com/stancl/tenancy/issue/63)

## [v1.4.0 (2019-07-03)](https://github.com/stancl/tenancy/compare/v1.3.1...v1.4.0)

### Added

- Predis support [#59](https://github.com/stancl/tenancy/pull/59)

## [v1.3.1 (2019-05-06)](https://github.com/stancl/tenancy/compare/v1.3.0...v1.3.1)

### Fixed
- Fix jobs [#38](https://github.com/stancl/tenancy/pull/38)
- Fix tests for 5.8 [#41](https://github.com/stancl/tenancy/issues/41)


## [v1.3.0 (2019-02-27)](https://github.com/stancl/tenancy/compare/v1.2.0...v1.3.0)

### Added
- Add 5.8 support [#33](https://github.com/stancl/tenancy/pull/33)


## [v1.2.0 (2019-02-15)](https://github.com/stancl/tenancy/compare/v1.1.3...v1.2.0)

### Added
- Add `Tenancy` facade [#29](https://github.com/stancl/tenancy/issues/29) [`987c54f`](https://github.com/stancl/tenancy/commit/987c54f04e6ff3bdef068d92da6a9ace847f6c37)


## [v1.1.3 (2019-02-13)](https://github.com/stancl/tenancy/compare/v1.1.2...v1.1.3)

### Fixed
- Fix CacheManager (it merged tags incorrectly), write tests for CacheManager [#31](https://github.com/stancl/tenancy/issues/31) [`a2d68b1`](https://github.com/stancl/tenancy/commit/a2d68b12611350f70befa3eb97fb56c99d006b54)


## [v1.1.2 (2019-02-13)](https://github.com/stancl/tenancy/compare/v1.1.1...v1.1.2)

### Fixed
- Fix small bug in CacheManager [`d4d4119`](https://github.com/stancl/tenancy/commit/d4d411975496272158d7823597427fad8966fff8)


## [v1.1.1 (2019-02-11)](https://github.com/stancl/tenancy/compare/v1.1.0...v1.1.1)

### Fixed
- Fix "Associative arrays are stored as objects" [#28](https://github.com/stancl/tenancy/issues/28)


## [v1.1.0 (2019-02-10)](https://github.com/stancl/tenancy/compare/v1.0.0...v1.1.0)

### Added
- Add array support to the storage [#27](https://github.com/stancl/tenancy/pull/27)

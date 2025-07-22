# Contributing

## Code style

php-cs-fixer will fix code style violations in your pull requests.

To run it locally, use `composer cs`.

## Running tests

Run `composer docker-up` to start the containers. Then run `composer test` to run the tests.

If you need to pass additional flags to phpunit, use `composer test --`, e.g. `composer test -- --filter="foo"`. Alternatively, you can use `./test --filter="foo"`

If you want to run a specific test (or test file), you can also use `./t 'name of the test'`. This is equivalent to `./test --no-coverage --filter 'name of the test'` (`--no-coverage` speeds up the execution time).

When you're done testing, run `composer docker-down` to shut down the containers.

### Debugging tests

If you're developing some feature and you encounter `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry` errors, it's likely that some PHP errors were thrown in past test runs and prevented the test cleanup from running properly.

To fix this, simply delete the database memory by shutting down containers and starting them again: `composer docker-down && composer docker-up`.

Same thing for `SQLSTATE[HY000]: General error: 1615 Prepared statement needs to be re-prepared`.

### Docker on Apple Silicon

Run `composer docker-m1` to symlink `docker-compose-m1.override.yml` to `docker-compose.override.yml`. This will reconfigure a few services in the docker compose config to be compatible with M1.

2025 note: By now only MSSQL doesn't have good M1 support. The override also started being a bit problematic, having issues with starts, often requiring multiple starts. This often makes the original image in docker-compose more stable, even if it's amd64-only. With Rosetta enabled, you should be able to use it without issues.

### Coverage reports

To run tests and generate coverage reports, use `composer test-full`.

To view the coverage reports in your browser, use `composer coverage` (works on macOS; on other operating systems you can manually open `coverage/phpunit/html/index.html` in your browser).

### Rebuilding containers

If you need to rebuild the container for any reason (e.g. a change in `Dockerfile`), you can use `composer docker-rebuild`.

## PHPStan

Use `composer phpstan` to run our phpstan suite.

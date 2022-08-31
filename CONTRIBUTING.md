# Contributing

## Code style

php-cs-fixer will fix code style violations in your pull requests.

## Running tests

Run `composer docker-up` to start the containers. Then run `composer test` to run the tests.

If you need to pass additional flags to phpunit, use `./test --foo` instead of `composer test --foo`. Composer scripts unfortunately don't pass CLI arguments.

When you're done testing, run `composer docker-down` to shut down the containers.

### Docker on M1

Run `composer docker-m1` to symlink `docker-compose-m1.override.yml` to `docker-compose.override.yml`. This will reconfigure a few services in the docker compose config to be compatible with M1.

### Coverage reports

To run tests and generate coverage reports, use `composer test-full`.

To view the coverage reports in your browser, use `composer coverage` (works on macOS; on other operating systems you can manually open `coverage/phpunit/html/index.html` in your browser).

### Rebuilding containers

If you need to rebuild the container for any reason (e.g. a change in `Dockerfile`), you can use `composer docker-rebuild`.

## PHPStan

Use `composer phpstan` to run our phpstan suite.

# Contributing

## Code style

php-cs-fixer will fix code style violations in your pull requests.

## Running tests

Run `composer docker-up` to start the containers. Then run `composer test` to run the tests.

If you need to pass additional flags to phpunit, use `./test --foo` instead of `composer test --foo`. Composer scripts unfortunately don't pass CLI arguments.

If you want to run a specific test (or test file), you can also use `./t 'name of the test'`. This is equivalent to `./test --no-coverage --filter 'name of the test'` (`--no-coverage` speeds up the execution time).

When you're done testing, run `composer docker-down` to shut down the containers.

### Debugging tests

If you're developing some feature and you encounter `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry` errors, it's likely that some PHP errors were thrown in past test runs and prevented the test cleanup from running properly.

To fix this, simply delete the database memory by shutting down containers and starting them again: `composer docker-down && composer docker-up`.

### Docker on M1

Run `composer docker-m1` to symlink `docker-compose-m1.override.yml` to `docker-compose.override.yml`. This will reconfigure a few services in the docker compose config to be compatible with M1.

to `docker-compose.override.yml` to make `docker-compose up -d` work on M1.

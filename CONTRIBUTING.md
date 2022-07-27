# Contributing

## Code style

php-cs-fixer will fix code style violations in your pull requests.

## Running tests

Run `composer docker-up` to start the containers. Then run `composer test` to run the tests.

When you're done testing, run `composer docker-down` to shut down the containers.

### Docker on M1

Run `composer docker-m1` to symlink `docker-compose-m1.override.yml` to `docker-compose.override.yml`. This will reconfigure a few services in the docker compose config to be compatible with M1.

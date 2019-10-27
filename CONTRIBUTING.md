# Contributing

## Code style
StyleCI will flag code style violations in your pull requests.

## Running tests

### With Docker
If you have Docker installed, simply run ./fulltest. When you're done testing, run docker-compose down to shut down the containers.

### Without Docker
If you run the tests of this package, please make sure you don't store anything in Redis @ 127.0.0.1:6379 db#14. The contents of this database are flushed everytime the tests are run.

Some tests are run only if the `CONTINUOUS_INTEGRATION` or `DOCKER` environment variables are set to true. This is to avoid things like bloating your MySQL instance with test databases.

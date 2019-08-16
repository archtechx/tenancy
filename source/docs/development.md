---
title: Development
description: Development | stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Development {#development}

## Running tests {#running-tests}

### With Docker {#with-docker}
If you have Docker installed, simply run ./test. When you're done testing, run docker-compose down to shut down the containers.

### Without Docker {#without-docker}
If you run the tests of this package, please make sure you don't store anything in Redis @ 127.0.0.1:6379 db#14. The contents of this database are flushed everytime the tests are run.

Some tests are run only if the CI, TRAVIS and CONTINUOUS_INTEGRATION environment variables are set to true. This is to avoid things like bloating your MySQL instance with test databases.
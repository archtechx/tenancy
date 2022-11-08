#!/bin/bash

docker-compose exec -T test vendor/bin/pest --no-coverage --filter "$@"

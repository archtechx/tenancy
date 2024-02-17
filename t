#!/bin/bash

docker-compose exec -e COLUMNS=$(tput cols) -T test vendor/bin/pest --color=always --no-coverage --filter "$@"

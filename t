#!/usr/bin/env bash

if [[ "${CLAUDECODE}" != "1" ]]; then
    COLOR_FLAG="--colors=always"
else
    COLOR_FLAG="--colors=never"
fi

docker compose exec -e COLUMNS=$([ -t 2 ] && tput cols || echo 120) -T test vendor/bin/pest ${COLOR_FLAG} --no-coverage --filter "$@"

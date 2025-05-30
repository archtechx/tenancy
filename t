#!/bin/bash

if [[ "${CLAUDECODE}" != "1" ]]; then
    COLOR_FLAG="--color=always"
else
    COLOR_FLAG=""
fi

docker compose exec -e COLUMNS=$(tput cols) -T test vendor/bin/pest ${COLOR_FLAG} --no-coverage --filter "$@"

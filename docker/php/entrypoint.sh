#!/bin/sh
set -eu

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views

if [ "${1:-}" = "php-fpm" ]; then
    php artisan migrate --force
fi

exec docker-php-entrypoint "$@"

#!/bin/sh
# Prepare the storage volume. Do not migrate here.
set -eu
cd /var/www/html
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R a+rX storage bootstrap/cache
umask 022

if [ "${1:-}" = "php-fpm" ]; then
    exec docker-php-entrypoint "$@"
fi

exec gosu www-data "$@"

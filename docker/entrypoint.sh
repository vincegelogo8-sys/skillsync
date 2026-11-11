#!/bin/sh
set -eu

PORT="${PORT:-10000}"
export PORT

DATA_STORAGE=/var/data/storage
APP_STORAGE=/var/www/html/storage

mkdir -p \
    "$DATA_STORAGE/app/private/proposals" \
    "$DATA_STORAGE/app/public" \
    "$DATA_STORAGE/framework/cache/data" \
    "$DATA_STORAGE/framework/sessions" \
    "$DATA_STORAGE/framework/views" \
    "$DATA_STORAGE/logs" \
    /var/www/html/bootstrap/cache

if [ -e "$APP_STORAGE" ] && [ ! -L "$APP_STORAGE" ]; then
    rmdir "$APP_STORAGE"
fi

ln -s "$DATA_STORAGE" "$APP_STORAGE"
chown -R www-data:www-data "$DATA_STORAGE" /var/www/html/bootstrap/cache
chmod -R ug+rwX "$DATA_STORAGE" /var/www/html/bootstrap/cache

php artisan storage:link --force
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php-fpm -D
exec nginx -g 'daemon off;'

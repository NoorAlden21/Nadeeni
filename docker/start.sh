#!/bin/sh
set -e

php artisan package:discover --ansi
php artisan config:cache
php artisan view:cache
php artisan migrate --force

chown -R www-data:www-data storage bootstrap/cache

php-fpm -D
exec nginx -g "daemon off;"

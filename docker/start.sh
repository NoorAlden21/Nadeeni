#!/bin/sh
set -e

php artisan package:discover --ansi
php artisan config:cache
php artisan view:cache
php artisan migrate --force

# Free Render has no shell and no pre-deploy command. Set SEED_ADMIN=true
# for one boot to create the platform admin, then delete that variable.
# Leaving it set resets the admin password on every restart.
if [ "$SEED_ADMIN" = "true" ]; then
  php artisan db:seed --force
fi

chown -R www-data:www-data storage bootstrap/cache

php-fpm -D
exec nginx -g "daemon off;"

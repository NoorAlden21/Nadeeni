FROM php:8.3-fpm-alpine

RUN apk add --no-cache bash nginx libpq oniguruma \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev oniguruma-dev \
 && docker-php-ext-install pdo_pgsql mbstring bcmath opcache \
 && apk del .build-deps \
 && printf '\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies before copying the app. Later code edits reuse this
# layer until composer.lock changes.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer dump-autoload --optimize --no-scripts \
 && sed -i 's/\r$//' docker/start.sh \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80

CMD ["sh", "docker/start.sh"]

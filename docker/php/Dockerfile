# Targets: app (php-fpm) and web (nginx + public/).

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
RUN npm run build

# Pinned to a release with the PHP2Shell fixes (CVE-2026-109618, CVE-2026-109619).
# Only move this forward: never below 8.4.27.
FROM php:8.4.27-fpm-bookworm AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu libicu-dev libzip-dev unzip $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache bcmath pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
COPY --from=assets /app/public ./public

RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi --no-interaction \
    && rm -f /usr/bin/composer \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R a+rX storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/zz-michicast.conf /usr/local/etc/php-fpm.d/zz-michicast.conf
COPY docker/php/michicast.ini /usr/local/etc/php/conf.d/michicast.ini
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
EXPOSE 9000

FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
RUN mkdir -p /var/www/storage/app/public

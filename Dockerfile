# syntax=docker/dockerfile:1

# Image PHP multi-stage (docs/conception.md §7.6).

FROM composer:2.10.3 AS composer

FROM php:8.5.10-fpm AS base
WORKDIR /app
COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
# var/data appartient à www-data : un volume nommé monté dessus hérite de ce propriétaire.
RUN mkdir -p var/data && chown -R www-data:www-data var
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM base AS dev
# www-data reprend l'UID et le GID de l'hôte : le code monté reste inscriptible sous Linux comme sous macOS.
ARG HOST_UID=33
ARG HOST_GID=33
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip $PHPIZE_DEPS \
    && pecl install xdebug-3.5.3 \
    && docker-php-ext-enable xdebug \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && groupmod -o -g "$HOST_GID" www-data \
    && usermod -o -u "$HOST_UID" -g "$HOST_GID" www-data \
    && chown -R www-data:www-data /app/var
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer \
    XDEBUG_MODE=off
USER www-data

FROM base AS build
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV APP_ENV=prod \
    COMPOSER_HOME=/tmp/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
# cache:warmup génère aussi var/cache/prod/*.preload.php, lu par config/preload.php.
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && bin/console cache:warmup

FROM base AS prod
COPY docker/php/conf.d/app.prod.ini "$PHP_INI_DIR/conf.d/zz-app.prod.ini"
# Code à root (non modifiable par www-data), var/ à www-data, sans dupliquer la couche du cache.
COPY --from=build --exclude=var --exclude=docker /app /app
COPY --from=build --chown=www-data:www-data /app/var /app/var
USER www-data

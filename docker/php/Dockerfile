FROM composer:2.11 AS composer

FROM php:8.4-fpm-alpine3.24

RUN apk add --no-cache \
        git \
        icu-libs \
        libpq \
        libzip \
        unzip \
    && apk add --no-cache --virtual .build-dependencies \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-dependencies

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

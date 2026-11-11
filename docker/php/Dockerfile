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

ARG WITH_PCOV=0

RUN if [ "$WITH_PCOV" = "1" ]; then \
        apk add --no-cache --virtual .pcov-build-dependencies $PHPIZE_DEPS \
        && wget -q https://pecl.php.net/get/pcov-1.0.12.tgz -O /tmp/pcov.tgz \
        && pecl install /tmp/pcov.tgz \
        && docker-php-ext-enable pcov \
        && apk del .pcov-build-dependencies \
        && rm -f /tmp/pcov.tgz; \
    fi

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

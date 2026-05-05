FROM php:8.2-cli-bookworm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git make unzip zip \
    && install-php-extensions \
        ctype \
        dom \
        mbstring \
        pdo_sqlite \
        simplexml \
        sqlite3 \
        xml \
        xmlwriter \
        zip \
    && mkdir -p /tmp/composer \
    && chmod -R 1777 /tmp/composer \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /app

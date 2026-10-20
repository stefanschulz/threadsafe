# Development container for ThreadSafe: PHP CLI with the database driver and Composer.
# The host PHP has no database driver, so tests and the CLI run in this container.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/composer \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

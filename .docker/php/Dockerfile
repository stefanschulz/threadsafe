# Development container for ThreadSafe: PHP CLI with both database interfaces (pdo_mysql, mysqli), Composer, unzip and the
# MariaDB command line client, `age` and WP-CLI (the plain tools that the tests use as oracles for archives, dumps and encrypted archives).
# The host PHP has no database driver, so tests and the CLI run in this container.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip mariadb-client age \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql mysqli

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# WP-CLI: it seeds the WordPress sites of the stack and is the oracle of their tests (`wp search-replace --dry-run`). A tool of the stack,
# never a dependency of ThreadSafe; it runs on this PHP, which has mysqli.
COPY --from=wordpress:cli-php8.4 /usr/local/bin/wp /usr/local/bin/wp

ENV COMPOSER_HOME=/composer \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

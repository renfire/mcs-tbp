# Image to run the TBP test suite without a local PHP installation.
#
# Build and run through bin/test, or manually from the project root:
#
#   docker build -f docker/test.Dockerfile -t tbp-test .
#   docker run --rm tbp-test

ARG PHP_VERSION=8.3

FROM composer:2.8 AS composer

FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install pcov \
    && docker-php-ext-enable pcov

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Dependencies first, so the layer is cached while only sources change. There is no composer.lock, and
# composer.json sets minimum-stability to dev, so --prefer-stable avoids resolving PHPUnit to a dev branch.
COPY composer.json ./
RUN composer update --no-interaction --no-progress --prefer-dist --prefer-stable --no-autoloader

COPY src ./src
COPY test ./test
RUN composer dump-autoload

ENTRYPOINT ["vendor/bin/phpunit", "-c", "test/phpunit.xml"]

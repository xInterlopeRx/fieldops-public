# syntax=docker/dockerfile:1.7

# Build the pinned official Bitwarden Rust SDK helper as a static Alpine binary.
FROM rust:1.88-alpine@sha256:9dfaae478ecd298b6b5a039e1f2cc4fc040fc818a2de9aa78fa714dea036574d AS bws-helper-builder

RUN apk add --no-cache build-base perl openssl-dev pkgconf

WORKDIR /build
COPY docker/bws-helper/Cargo.toml docker/bws-helper/Cargo.lock ./
COPY docker/bws-helper/src ./src
RUN cargo build --locked --release

# Export only the host helper when developers use Herd instead of the PHP image.
FROM scratch AS bws-helper-export

COPY --from=bws-helper-builder /build/target/release/fieldops-bws-helper /fieldops-bws-helper

# Shared PHP runtime extensions. PHP 8.5 FPM supplies Zend OPcache.
FROM php:8.5-fpm-alpine@sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7 AS php-extensions

RUN apk add --no-cache ca-certificates freetype icu-libs libjpeg-turbo libpng libwebp libzip libpq oniguruma sqlite-libs libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev oniguruma-dev postgresql-dev sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl mbstring pcntl pdo_pgsql pdo_sqlite zip \
    && apk del .build-deps

COPY --from=bws-helper-builder --chmod=0555 /build/target/release/fieldops-bws-helper /usr/local/bin/fieldops-bws-helper

WORKDIR /var/www/html

# Development target retained for compose.dev.yml, which bind-mounts the source.
FROM php-extensions AS dev-runtime

RUN apk add --no-cache bash git unzip
COPY --from=composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Build Composer dependencies without development packages or runtime secrets.
FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer-build

WORKDIR /var/www/html
COPY . .
ENV APP_ENV=production APP_DEBUG=false COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

# Build Vue/TypeScript and Vite assets from the locked npm dependency graph.
FROM node:22-alpine@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402 AS frontend-build

WORKDIR /var/www/html
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=composer-build /var/www/html/vendor/tightenco/ziggy /var/www/html/vendor/tightenco/ziggy
COPY . .
RUN npm run build

# Production PHP-FPM image used by web, worker, scheduler, and one-off commands.
FROM php-extensions AS production

WORKDIR /var/www/html
COPY --from=composer-build --chown=www-data:www-data /var/www/html/ /var/www/html/
COPY --from=frontend-build --chown=www-data:www-data /var/www/html/public/build/ /var/www/html/public/build/
RUN rm -f public/hot \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production APP_DEBUG=false
USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r '$socket = @fsockopen("127.0.0.1", 9000); exit($socket === false ? 1 : 0);'

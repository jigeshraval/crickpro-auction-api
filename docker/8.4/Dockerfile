# CrickPro Auction API — PHP 8.4 (cli + artisan serve)
#
# Kept deliberately minimal: php:8.4-cli with `php artisan serve` so there is
# no nginx/fpm to wire up. Suitable for local dev; for production put a real
# web server in front. Mirrors crickpro-draft-api's Dockerfile.
FROM php:8.4-cli

LABEL maintainer="CrickPro"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC

WORKDIR /var/www/html

# System libraries required to build the PHP extensions below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        curl \
        ca-certificates \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libicu-dev \
        default-mysql-client \
        sqlite3 \
        libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Core PHP extensions Laravel needs.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip \
        intl \
        exif

# Redis via PECL (session/cache driver + AuthThrottler rate-limit counters).
RUN pecl install redis \
    && docker-php-ext-enable redis

# Sensible upload limits for profile-photo uploads.
RUN { \
        echo "post_max_size = 20M"; \
        echo "upload_max_filesize = 20M"; \
        echo "memory_limit = 512M"; \
        echo "variables_order = EGPCS"; \
    } > /usr/local/etc/php/conf.d/99-crickpro.ini

# Composer (copied from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 80

# `php artisan serve` binding to all interfaces on the container's port 80.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=80"]

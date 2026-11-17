# imeihub dev container.
#
# Uses the official php:8.4-apache image which bundles Apache 2.4 and
# PHP-FPM under mod_php. We layer on the extensions the app needs:
#
#   pdo_mysql  - DB access (includes/db.php)
#   zip        - inline xlsx writer (scripts/build-*.php)
#   intl       - Intl.NumberFormat for the credit display helpers
#   gd         - placeholder for any future QR / image generation
#
# curl, openssl, json, mbstring, fileinfo, hash and tokenizer are
# already part of the base image, so no apt install needed for those.
#
# Build:
#   docker compose build
# Run:
#   docker compose up -d

FROM php:8.4-apache

# Apt deps for the extensions we compile in.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        unzip \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Configure + install the extensions in one layer.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        zip \
        intl \
        gd \
        opcache

# Drop-in dev-friendly PHP ini (display errors, generous limits).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-imeihub.ini

# Apache: enable rewrite (clean URLs for /signup, /dashboard, etc.) and
# point the default vhost at the repo's document root.
RUN a2enmod rewrite headers
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Project lives in /var/www/html, owned by www-data so PHP can write
# session cookies and any (gitignored) generated artifacts.
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

# Apache will run as PID 1 via the upstream entrypoint.
EXPOSE 80

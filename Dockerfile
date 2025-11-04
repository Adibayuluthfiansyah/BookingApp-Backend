# Tahap 1: Base image dengan PHP 8.2
FROM php:8.2-fpm-alpine AS base
WORKDIR /var/www/html

# Install dependensi sistem (SUDAH DIPERBAIKI)
RUN apk update && \
    apk add --no-cache \
    curl zip unzip git supervisor nginx build-base \
    libzip-dev libpng-dev jpeg-dev freetype-dev \
    oniguruma-dev libxml2-dev zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    gd pdo pdo_mysql mbstring exif pcntl bcmath xml zip

# Install Composer (SUDAH DIPERBAIKI)
COPY --from=docker.io/linux/amd64/composer:latest /usr/bin/composer /usr/bin/composer

# Buat user non-root
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www
USER www

# -----------------------------------------------------------------
# Tahap 2: Builder (untuk production)
# -----------------------------------------------------------------
FROM base AS builder
USER root
RUN apk add --no-cache build-base
USER www

WORKDIR /var/www/html
COPY --chown=www:www . .
RUN composer install --optimize-autoloader --no-dev --no-scripts

# -----------------------------------------------------------------
# Tahap 3: Development Image
# -----------------------------------------------------------------
FROM base AS development
USER root
WORKDIR /var/www/html
RUN chown -R www:www /var/www/html
USER www
CMD ["php-fpm"]

# -----------------------------------------------------------------
# Tahap 4: Production Image (Optimized)
# -----------------------------------------------------------------
FROM base AS production
USER root
WORKDIR /var/www/html
COPY --from=builder --chown=www:www /var/www/html /var/www/html
RUN chown -R www:www /var/www/html
USER www
CMD ["php-fpm"]
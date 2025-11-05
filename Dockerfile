# Tahap 1: Base image dengan PHP 8.2 (sebagai ROOT)
FROM php:8.2-fpm-alpine AS base
WORKDIR /var/www/html

# Install dependensi sistem
RUN apk update && \
    apk add --no-cache \
    curl zip unzip git \
    libzip-dev libpng-dev jpeg-dev freetype-dev \
    oniguruma-dev libxml2-dev \
    mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    gd pdo pdo_mysql mbstring exif pcntl bcmath xml zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------------
# Tahap 2: Builder (untuk production)
# -----------------------------------------------------------------
FROM base AS builder

WORKDIR /var/www/html

COPY . .

# Buat folder storage sebelum composer install (PENTING!)
RUN mkdir -p storage/framework/{cache,sessions,views} \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public \
    && mkdir -p bootstrap/cache

# Ganti kepemilikan file
RUN chown -R www-data:www-data /var/www/html

# Jalankan composer sebagai user 'www-data'
USER www-data
RUN composer install --optimize-autoloader --no-dev --no-scripts

# Kembali ke root
USER root

# -----------------------------------------------------------------
# Tahap 3: Development Image
# -----------------------------------------------------------------
FROM base AS development

WORKDIR /var/www/html

# Buat folder storage untuk development
RUN mkdir -p storage/framework/{cache,sessions,views} \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache


CMD ["php-fpm"]

# -----------------------------------------------------------------
# Tahap 4: Production Image (Optimized)
# -----------------------------------------------------------------
FROM base AS production

WORKDIR /var/www/html

# Copy file yang sudah di-build
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

# Pastikan folder ada dan set permissions (DOUBLE CHECK!)
RUN mkdir -p storage/framework/{cache,sessions,views} \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

CMD ["php-fpm"]
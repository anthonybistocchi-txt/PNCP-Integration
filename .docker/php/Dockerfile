FROM php:8.3-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    curl \
    libonig-dev \
    libxml2-dev \
    libpq-dev

# Install extensions
RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd
RUN pecl install redis && docker-php-ext-enable redis

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

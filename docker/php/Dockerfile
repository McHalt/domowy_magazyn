FROM php:8.3-fpm-alpine

# Zależności systemowe
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    linux-headers

# Rozszerzenia PHP
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mbstring \
    intl \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Kopiuj composer.json i instaluj zależności (warstwa cache)
COPY composer.json ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

# Kopiuj resztę aplikacji
COPY . .

# Generuj autoload i uruchom post-install-cmd
RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var/

EXPOSE 9000
CMD ["php-fpm"]

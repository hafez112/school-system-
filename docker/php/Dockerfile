FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apache.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
COPY apps/api/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
RUN mkdir -p /var/www/storage/logs /var/www/storage/cache \
    && chown -R www-data:www-data /var/www/storage

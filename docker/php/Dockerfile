FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

COPY apache.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p /var/www/storage/logs /var/www/storage/cache \
    && chown -R www-data:www-data /var/www/storage

WORKDIR /var/www/html

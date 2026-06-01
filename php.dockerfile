FROM php:7.4-apache

RUN apt-get update && apt-get install -y libsqlite3-dev
RUN docker-php-ext-install pdo pdo_sqlite

RUN chown -R www-data:www-data /var/www/html
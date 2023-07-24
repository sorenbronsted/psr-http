FROM php:8-cli
RUN pecl install xdebug-3.2.0 && docker-php-ext-enable xdebug
COPY xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
# VOLUME /app
WORKDIR /app

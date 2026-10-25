# Runs the example server from this repository. Build with:
#
#   docker build -f run.dockerfile -t psr-http-run .
#   docker run --rm -p 8000:8000 psr-http-run
#
# PHP_VERSION can be overridden to compare runtimes:
#
#   docker build -f run.dockerfile --build-arg PHP_VERSION=8.1 -t psr-http-run .
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Composer extracts dist archives with unzip when ext-zip is unavailable.
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress

COPY src ./src
COPY example ./example

EXPOSE 8000

CMD ["php", "example/server.php"]

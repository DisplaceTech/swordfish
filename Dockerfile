# Composer builder
FROM composer:latest AS builder

# Set up build directory
WORKDIR /build

# Copy only composer files first
COPY server/ /build

# Install dependencies
RUN composer install --ignore-platform-reqs --no-scripts

# PHP runtime
FROM php:8.4-cli

RUN pecl install redis-6.2.0 \
  && docker-php-ext-enable redis

RUN docker-php-ext-install pcntl

# Set up runtime directory
WORKDIR /var/www/html

# Copy application files
COPY server/ /var/www/html/
COPY --from=builder /build/vendor/ /var/www/html/vendor/

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Default to port 8080 if SERVER_PORT not set
ENV SERVER_PORT=8080

EXPOSE 8080

CMD ["php", "server.php"]

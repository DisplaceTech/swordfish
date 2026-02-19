# Node.js builder for SPA
FROM node:20-alpine AS spa-builder

WORKDIR /build

COPY frontend/package*.json ./frontend/
RUN cd frontend && npm ci

COPY frontend/ ./frontend/
RUN cd frontend && npm run build
# Output lands at /build/server/static/dist/ (per vite.config.js outDir: '../server/static/dist')

# Composer builder
FROM composer:latest AS composer-builder

WORKDIR /build

COPY server/ /build
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
COPY --from=composer-builder /build/vendor/ /var/www/html/vendor/
# Overwrite any pre-committed dist/ with the freshly built SPA assets
COPY --from=spa-builder /build/server/static/dist/ /var/www/html/static/dist/

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Default to port 8080 if SERVER_PORT not set
ENV SERVER_PORT=8080

EXPOSE 8080

CMD ["php", "server.php"]
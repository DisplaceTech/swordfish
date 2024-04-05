FROM php:8.3-cli

RUN pecl install redis-6.0.2 \
  && docker-php-ext-enable redis

RUN docker-php-ext-install pcntl

COPY server /usr/src/server
WORKDIR /usr/src/server

EXPOSE 8080/tcp

CMD [ "php", "./server.php"]

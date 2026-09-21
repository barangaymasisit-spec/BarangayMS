FROM php:8.5-cli-alpine3.24

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS mariadb-connector-c-dev \
    && docker-php-ext-install mysqli \
    && apk del .build-deps

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads \
    && chmod 0775 /var/www/html/uploads \
    && rm -f /var/www/html/install_db.php

ENV PORT=8080

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /var/www/html"]
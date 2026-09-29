FROM php:8.5-cli-alpine3.24

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS mariadb-connector-c-dev \
    && docker-php-ext-install mysqli \
    && apk del .build-deps

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads \
    && chmod 0775 /var/www/html/uploads \
    && rm -f /var/www/html/install_db.php

ENV PORT=8080
# The built-in server handles one request at a time by default; workers let a slow
# request (backup, upload) run without freezing everyone else.
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

CMD ["sh", "-c", "php -d display_errors=0 -d log_errors=1 -d expose_php=0 -d upload_max_filesize=64M -d post_max_size=64M -S 0.0.0.0:${PORT} -t /var/www/html"]
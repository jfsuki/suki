FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql \
    && apk add --no-cache libcurl curl-dev \
    && docker-php-ext-install curl

WORKDIR /app
COPY . .

RUN if [ -f project/.env.example ]; then cp project/.env.example project/.env; fi \
    && if [ -f framework/composer.json ]; then \
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
        && composer install --no-interaction --prefer-dist --working-dir=framework; \
    fi

ENV DB_DRIVER=sqlite \
    GEMINI_ENABLED=0 \
    OPENROUTER_ENABLED=0

CMD ["php", "framework/tests/run.php"]

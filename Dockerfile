FROM node:24.14.1-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM dunglas/frankenphp:1.12.7-php8.4-bookworm AS runtime-base

RUN install-php-extensions gd pdo_sqlite pcntl opcache zip intl \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl ffmpeg git poppler-utils sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8.10 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chmod +x /app/docker/entrypoint.sh \
    && mkdir -p /app/storage/app/private /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /data /wiki

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--admin-host=0.0.0.0", "--admin-port=2019", "--workers=2", "--max-requests=500"]

FROM runtime-base AS test

RUN composer install --no-interaction --no-scripts --prefer-dist \
    && composer dump-autoload --classmap-authoritative --no-interaction

CMD ["php", "artisan", "test"]

FROM runtime-base AS runtime

RUN rm -rf /app/tests

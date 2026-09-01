FROM dunglas/frankenphp:1.12.7-php8.4-bookworm AS php-base

RUN install-php-extensions gd pdo_sqlite pcntl opcache zip intl \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl ffmpeg git poppler-utils sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8.10 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM php-base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

# 前端构建依赖 vendor：resources/css/app.css 直接 @import Flux 的样式表，
# 并通过 @source 扫描 vendor 下的 Blade 模板收集 Tailwind class。
FROM node:24.14.1-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM vendor AS runtime-base

COPY . .
COPY --from=frontend /app/public/build ./public/build
# dump-autoload 会触发 package:discover，进而引导应用；
# 构建期没有数据库文件，改用内存库避免 SQLite 连接失败。
RUN DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chmod +x /app/docker/entrypoint.sh \
    && mkdir -p /app/storage/app/private /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /data /wiki

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--admin-host=0.0.0.0", "--admin-port=2019", "--workers=2", "--max-requests=500"]

FROM runtime-base AS test

# .env 被 .dockerignore 排除，缺失时测试运行期会持续报读取失败告警。
# 仅测试镜像补一份示例配置；实际取值仍由容器环境变量覆盖。
RUN cp .env.example .env

RUN DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
    sh -c 'composer install --no-interaction --no-scripts --prefer-dist \
    && composer dump-autoload --classmap-authoritative --no-interaction'

CMD ["php", "artisan", "test"]

FROM runtime-base AS runtime

RUN rm -rf /app/tests

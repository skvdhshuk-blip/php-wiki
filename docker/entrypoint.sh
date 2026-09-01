#!/bin/sh
set -eu

mkdir -p /app/storage/app/private /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /data /wiki

if [ -z "${APP_KEY:-}" ]; then
    key_file=/app/storage/app/app-key
    if [ ! -f "$key_file" ]; then
        umask 077
        php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;' > "$key_file"
    fi
    APP_KEY="$(tr -d '\r\n' < "$key_file")"
    export APP_KEY
fi

database_path="${DB_DATABASE:-/data/database.sqlite}"
# 内存库无法由独立进程预置：migrate 与 init 的结果会随进程一起消失，
# 后续命令只会看到空库。测试容器使用内存库，自行建表。
if [ "$database_path" != ':memory:' ]; then
    mkdir -p "$(dirname "$database_path")"
    touch "$database_path"

    php artisan migrate --force --no-interaction
    php artisan php-wiki:init --no-interaction
fi

exec "$@"

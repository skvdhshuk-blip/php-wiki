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
if [ "$database_path" != ':memory:' ]; then
    mkdir -p "$(dirname "$database_path")"
    touch "$database_path"
fi

php artisan migrate --force --no-interaction
php artisan php-wiki:init --no-interaction

exec "$@"

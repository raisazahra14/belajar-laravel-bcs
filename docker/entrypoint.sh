#!/bin/sh
set -e

CONTAINER_ROLE="${CONTAINER_ROLE:-app}"
APP_ENV_VALUE="${APP_ENV:-production}"

has_app_key() {
    if [ -n "${APP_KEY:-}" ]; then
        return 0
    fi

    if [ -f /var/www/.env ] && grep -Eq '^APP_KEY=[^[:space:]#]+' /var/www/.env; then
        return 0
    fi

    return 1
}

if [ "$CONTAINER_ROLE" = "app" ]; then
    # Ensure environment file exists for web/app runtime
    if [ ! -f /var/www/.env ] && [ -f /var/www/.env.example ]; then
        cp /var/www/.env.example /var/www/.env
    fi

    # Install dependencies if anonymous /var/www/vendor volume lacks autoloader
    if [ ! -f /var/www/vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi

    if ! has_app_key; then
        if [ "$APP_ENV_VALUE" = "production" ] || [ "$APP_ENV_VALUE" = "staging" ]; then
            echo "ERROR: APP_KEY is not set. Refusing to auto-generate a key in ${APP_ENV_VALUE} environment." >&2
            exit 1
        fi

        php artisan key:generate --no-interaction
    fi

    # Ensure required Laravel storage subdirectories exist on fresh volume mounts
    mkdir -p \
        /var/www/storage/app/private \
        /var/www/storage/app/public \
        /var/www/storage/framework/cache/data \
        /var/www/storage/framework/sessions \
        /var/www/storage/framework/views \
        /var/www/storage/logs

    # Create symbolic link for public storage if missing
    if [ ! -L /var/www/public/storage ] && [ ! -e /var/www/public/storage ]; then
        php artisan storage:link --no-interaction || true
    fi
else
    if ! has_app_key; then
        if [ "$APP_ENV_VALUE" = "production" ] || [ "$APP_ENV_VALUE" = "staging" ]; then
            echo "ERROR: APP_KEY is not set for ${CONTAINER_ROLE} container in ${APP_ENV_VALUE} environment." >&2
            exit 1
        fi
    fi
fi

# Execute container command
exec "$@"

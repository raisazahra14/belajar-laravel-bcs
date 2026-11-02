#!/bin/sh
set -e

# Ensure environment file exists
if [ ! -f /var/www/.env ]; then
    cp /var/www/.env.example /var/www/.env
fi

# Install dependencies if vendor directory is empty
if [ ! -d /var/www/vendor ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Generate application key if not set
php artisan key:generate --force --no-interaction || true

# Create symbolic link for public storage
php artisan storage:link --force --no-interaction || true

# Execute container command
exec "$@"

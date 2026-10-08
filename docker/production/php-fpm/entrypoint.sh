#!/bin/sh
set -e

# Initialize storage directory if empty
# -----------------------------------------------------------
# If the storage directory is empty, copy the initial contents
# and set the correct permissions.
# -----------------------------------------------------------
# if [ ! "$(ls -A /var/www/storage)" ]; then
#   echo "Initializing storage directory..."
#   cp -R /var/www/storage-init/. /var/www/storage
#   chown -R www-data:www-data /var/www/storage
# fi

# Remove storage-init directory
rm -rf /var/www/storage-init

# Run Laravel migrations
# -----------------------------------------------------------
# Ensure the database schema is up to date.
# -----------------------------------------------------------
if [ "${1:-}" = "php-fpm" ]; then
    php artisan config:clear

    php artisan migrate --force

    # Publish the documentation articles kept in resources/docs. A failure
    # leaves the previous version online and must not stop the application.
    php artisan docs:sync || echo "Documentation sync failed, the previous articles stay published." >&2

    php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear && php artisan optimize:clear

    php artisan storage:link --silent

    # Clear and cache configurations
    # -----------------------------------------------------------
    # Improves performance by caching config and routes.
    # -----------------------------------------------------------
    php artisan optimize
fi

# Run the default command
exec "$@"

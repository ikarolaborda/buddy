#!/bin/sh
set -e

# Ensure database exists and is migrated
touch /var/www/html/database/database.sqlite
php /var/www/html/artisan migrate --force --quiet 2>/dev/null

# Execute the passed command
exec "$@"

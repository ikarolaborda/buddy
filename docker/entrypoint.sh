#!/bin/sh
set -e

APP_DIR="/var/www/html"

# 1. Environment file
if [ ! -f "$APP_DIR/.env" ]; then
    echo "[buddy] No .env found — copying from .env.example"
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

# 2. Dependencies
if [ ! -d "$APP_DIR/vendor" ] || [ ! -f "$APP_DIR/vendor/autoload.php" ]; then
    echo "[buddy] No vendor directory — installing dependencies"
    composer install --no-interaction --prefer-dist --optimize-autoloader --working-dir="$APP_DIR"
fi

# 3. Application key
if ! grep -q "^APP_KEY=base64:" "$APP_DIR/.env"; then
    echo "[buddy] No APP_KEY — generating application key"
    php "$APP_DIR/artisan" key:generate --force
fi

# 4. Database
if [ ! -f "$APP_DIR/database/database.sqlite" ]; then
    echo "[buddy] No database — creating SQLite file"
    touch "$APP_DIR/database/database.sqlite"
fi

php "$APP_DIR/artisan" migrate --force --quiet 2>/dev/null || true

# Hand off to the command
exec "$@"

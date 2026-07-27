#!/bin/sh
set -e

APP_DIR="/var/www/html"

# Refuse to run the local-dev image against a production environment. The
# failure this prevents is silent: this image serves on 8000, deployed ingress
# targets 8080, so a mistaken deploy hangs every request instead of erroring,
# which cost a live outage on 2026-07-27. The check is anchored to the IMAGE
# (BUDDY_IMAGE_ROLE is baked in by docker/Dockerfile) and not to env alone, so
# the production images under docker/production/ are unaffected: they never
# load this script.
if [ "${BUDDY_IMAGE_ROLE}" = "dev" ] && [ "${APP_ENV}" = "production" ] && [ "${BUDDY_ALLOW_DEV_IMAGE}" != "1" ]; then
    echo "[buddy] FATAL: refusing to start." >&2
    echo "[buddy]   image role : dev (built from docker/Dockerfile, serves on port 8000)" >&2
    echo "[buddy]   APP_ENV    : production (ingress targets port 8080)" >&2
    echo "[buddy] Nothing would listen on 8080, so every request would hang rather than fail." >&2
    echo "[buddy] Build a release image with scripts/build-image.sh:" >&2
    echo "[buddy]   docker/production/Dockerfile.octane -> API   (buddy:<sha>-octane)" >&2
    echo "[buddy]   docker/production/Dockerfile        -> fpm and worker (buddy:<sha>)" >&2
    echo "[buddy] Deliberate override for an emergency only: BUDDY_ALLOW_DEV_IMAGE=1" >&2
    exit 1
fi

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

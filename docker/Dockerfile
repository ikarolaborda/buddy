# LOCAL DEVELOPMENT IMAGE. Do not deploy this.
#
# It serves through `php artisan serve` on port 8000. Every deployed environment
# routes ingress to port 8080 and is served by the images under docker/production/
# (Dockerfile.octane for the API, Dockerfile for fpm and the worker). Deploying
# this image does not fail loudly, it hangs: nothing listens on 8080 and every
# request times out. That is exactly what happened on 2026-07-27.
#
# Release builds go through scripts/build-image.sh, which refuses this file.
# The BUDDY_IMAGE_ROLE marker below lets docker/entrypoint.sh refuse to boot
# this image against APP_ENV=production.

FROM php:8.5-cli

LABEL org.opencontainers.image.title="buddy-dev-local"
LABEL org.opencontainers.image.description="Local development image. Not deployable. See docker/production/ for release images."

ENV BUDDY_IMAGE_ROLE=dev

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring xml \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies into the image (used when vendor/ is not mounted from host)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize

# Smart entrypoint: bootstraps .env, vendor, APP_KEY, and database if missing
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0"]

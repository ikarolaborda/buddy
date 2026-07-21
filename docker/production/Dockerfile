# Production image (plan §11.4): dependencies and framework caches are
# compiled into the image, never installed at startup. Build only from a
# clean clone/worktree pinned to a reviewed SHA and tag with that SHA.
# Conventional Nginx + PHP-FPM; benchmark Octane/FrankenPHP separately
# before adopting long-lived workers.

FROM composer:2.8 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs

FROM php:8.5-fpm-alpine AS app

RUN apk add --no-cache nginx supervisor libpq-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && (docker-php-ext-enable opcache || true) \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

COPY --from=vendor /usr/bin/composer /usr/bin/composer

# Stale host caches must never ship: packages.php from a dev machine lists
# dev-only providers (Pail) absent from the no-dev vendor tree. Config is
# cached at container start, not build time — build-time caching would
# freeze empty env values into the image.
RUN cp docker/production/php.ini "$PHP_INI_DIR/conf.d/99-buddy.ini" \
    && cp docker/production/nginx.conf /etc/nginx/http.d/default.conf \
    && cp docker/production/supervisord.conf /etc/supervisord.conf \
    && rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/api/health || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]

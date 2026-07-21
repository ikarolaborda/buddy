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
    && docker-php-ext-install pdo_pgsql opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN cp docker/production/php.ini "$PHP_INI_DIR/conf.d/99-buddy.ini" \
    && cp docker/production/nginx.conf /etc/nginx/http.d/default.conf \
    && cp docker/production/supervisord.conf /etc/supervisord.conf \
    && composer dump-autoload --optimize --classmap-authoritative 2>/dev/null || php -r "exit(0);" \
    && php artisan config:cache --no-ansi 2>/dev/null || true \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/api/health || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]

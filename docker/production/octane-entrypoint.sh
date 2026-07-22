#!/bin/sh
set -e

# Octane workers freeze the booted app in memory, so cached config must
# exist before the first worker boots; caching here (not at build time)
# picks up the real container environment.
php artisan config:cache
php artisan route:cache

# Workers are sized for blocking I/O, not CPU: requests park on network
# waits (Postgres/Redis TLS, provider HTTP), so 6 workers on 0.5 vCPU is
# concurrency headroom, and inline ?sync=1 runs cannot starve the pool.
# max-requests recycles workers as a leak guard (ADR 0008).
exec php artisan octane:frankenphp \
    --host=0.0.0.0 \
    --port=8080 \
    --workers="${OCTANE_WORKERS:-6}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-250}"

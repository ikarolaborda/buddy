#!/bin/sh
# Bounded shutdown for Horizon on Azure Container Apps.
#
# The platform cuts a terminating replica's route to Redis at the same second
# it sends SIGTERM, and Horizon's terminate() begins with a Redis read; the
# exception is caught by the master loop, the worker processes are never
# signalled, and the replica lingered until the 600 s grace period killed it
# (observed after every stop on 2026-09-15). This wrapper forwards SIGTERM,
# gives in-flight work HORIZON_SHUTDOWN_GRACE seconds to finish, then kills the
# whole Horizon process group. When Redis is reachable Horizon still stops on
# its own first. The bound is an interruption budget, not a drain guarantee:
# an evaluation usually fits in it, a council never does, and a job killed here
# is recovered through the PostgreSQL lease, not through Redis.
set -u

GRACE="${HORIZON_SHUTDOWN_GRACE:-240}"
IDENT="revision=${CONTAINER_APP_REVISION:-unknown} replica=${CONTAINER_APP_REPLICA_NAME:-${HOSTNAME:-unknown}}"

log() {
    echo "horizon-entrypoint: $1 $IDENT" >&2
}

# setsid gives Horizon its own process group so the forced path can kill the
# supervisors and workers together, not only the master.
setsid php artisan horizon "$@" &
CHILD=$!
PGID=$(sed 's/.*) //' "/proc/$CHILD/stat" 2>/dev/null | awk '{print $3}')

if [ "$PGID" != "$CHILD" ]; then
    log "event=startup_warning detail=horizon_not_group_leader pid=$CHILD pgid=${PGID:-unknown}"
    PGID=""
fi

log "event=started pid=$CHILD pgid=${PGID:-none} grace_s=$GRACE"

SHUTDOWN_AT=""
FORCED=0

shutdown() {
    # One deadline per container: later signals must not restart it.
    trap '' TERM INT
    SHUTDOWN_AT=$(date +%s)
    log "event=shutdown_started pid=$CHILD grace_s=$GRACE"
    kill -TERM "$CHILD" 2>/dev/null || true
    waited=0

    while kill -0 "$CHILD" 2>/dev/null && [ "$waited" -lt "$GRACE" ]; do
        sleep 1
        waited=$((waited + 1))
    done

    if kill -0 "$CHILD" 2>/dev/null; then
        FORCED=1
        log "event=shutdown_forced elapsed_s=$waited pgid=${PGID:-none}"

        if [ -n "$PGID" ]; then
            kill -KILL "-$PGID" 2>/dev/null || true
        fi

        kill -KILL "$CHILD" 2>/dev/null || true
    fi
}

trap shutdown TERM INT

wait "$CHILD"
STATUS=$?

# A trapped signal interrupts the first wait; the second one collects the
# child's real exit status after shutdown() has run (127 means it was already
# collected, so the first status stands).
if [ -n "$SHUTDOWN_AT" ]; then
    wait "$CHILD" 2>/dev/null
    SECOND=$?
    [ "$SECOND" -ne 127 ] && STATUS=$SECOND
fi

if [ -n "$SHUTDOWN_AT" ]; then
    log "event=exited status=$STATUS forced=$FORCED elapsed_s=$(( $(date +%s) - SHUTDOWN_AT ))"
else
    log "event=exited status=$STATUS detail=horizon_exited_without_signal"
fi

exit "$STATUS"

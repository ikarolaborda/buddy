#!/bin/sh
# Bounded shutdown for Horizon on Azure Container Apps.
#
# The platform cuts a terminating replica's route to Redis at the same second
# it sends SIGTERM, and Horizon's terminate() begins with a Redis read; the
# exception is caught by the master loop, the worker processes are never
# signalled, and the replica lingered until the 600 s grace period killed it
# (observed after every stop on 2026-09-15). The image also inherited
# STOPSIGNAL SIGQUIT from php-fpm, which a PID 1 without a handler drops, so
# until 2026-09-16 no stop signal reached Horizon at all. This wrapper takes
# TERM, INT and QUIT, forwards SIGTERM to the master and to the workers (the
# workers finish their current job and exit even without Redis), ends as soon
# as no worker is left, and otherwise kills the whole Horizon process group at
# HORIZON_SHUTDOWN_GRACE seconds. When Redis is reachable Horizon still stops
# on its own first. The bound is an interruption budget, not a drain
# guarantee: an evaluation usually fits in it, a council never does, and a job
# killed here is recovered through the PostgreSQL lease, not through Redis.
set -u

GRACE="${HORIZON_SHUTDOWN_GRACE:-240}"
SETTLE="${HORIZON_SHUTDOWN_SETTLE:-10}"
IDENT="revision=${CONTAINER_APP_REVISION:-unknown} replica=${CONTAINER_APP_REPLICA_NAME:-${HOSTNAME:-unknown}}"

log() {
    echo "horizon-entrypoint: $1 $IDENT" >&2
}

case "$GRACE" in
    ''|*[!0-9]*)
        log "event=startup_warning detail=invalid_grace value=$GRACE using=240"
        GRACE=240
        ;;
esac

# A stop that arrives before Horizon is running is remembered here and served
# once the real handler is installed; without this an early SIGTERM would kill
# the wrapper and orphan Horizon.
PENDING=0
trap 'PENDING=1' TERM INT QUIT

# setsid gives Horizon its own process group so the forced path can kill the
# supervisors and workers together, not only the master.
setsid php artisan horizon "$@" &
CHILD=$!

group_of() {
    sed 's/.*) //' "/proc/$1/stat" 2>/dev/null | awk '{print $3}'
}

# The child becomes group leader only once setsid has run; give it a moment.
PGID=$(group_of "$CHILD")
tries=0
while [ "$PGID" != "$CHILD" ] && [ "$tries" -lt 50 ] && kill -0 "$CHILD" 2>/dev/null; do
    sleep 0.1
    tries=$((tries + 1))
    PGID=$(group_of "$CHILD")
done

if [ "$PGID" != "$CHILD" ]; then
    log "event=startup_warning detail=horizon_not_group_leader pid=$CHILD pgid=${PGID:-unknown}"
    PGID=""
fi

log "event=started pid=$CHILD pgid=${PGID:-none} grace_s=$GRACE"

SHUTDOWN_AT=""
FORCED=0

shutdown() {
    # One deadline per container: later signals must not restart it.
    trap '' TERM INT QUIT
    SHUTDOWN_AT=$(date +%s)
    log "event=shutdown_started pid=$CHILD grace_s=$GRACE"
    kill -TERM "$CHILD" 2>/dev/null || true
    pkill -TERM -f 'artisan horizon:work' 2>/dev/null || true
    waited=0
    idle_for=0

    # With Redis severed the master never gets past its own terminate(), so the
    # workers being gone is the signal that nothing useful is left to wait for.
    # A healthy master exits a few seconds after its last worker; SETTLE gives
    # it that time before the group is killed.
    while kill -0 "$CHILD" 2>/dev/null && [ "$waited" -lt "$GRACE" ]; do
        sleep 1
        waited=$((waited + 1))

        if pgrep -f 'artisan horizon:work' >/dev/null 2>&1; then
            idle_for=0
        else
            idle_for=$((idle_for + 1))
        fi

        if [ "$idle_for" -ge "$SETTLE" ]; then
            log "event=workers_drained elapsed_s=$waited"
            break
        fi
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

trap shutdown TERM INT QUIT

if [ "$PENDING" -eq 1 ]; then
    shutdown
fi

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

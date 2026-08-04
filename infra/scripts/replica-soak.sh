#!/usr/bin/env bash
#
# Replica soak — samples the criteria in docs/06 §6.8 against a running stack.
#
# Routing and stickiness are already proven (docs/01 "Read replica"). What this
# answers is the question a lab cannot: how far behind *this* replica runs under
# *this* load, and whether read-after-write is visibly stale to a client.
#
# Run it on staging with DB_READ_HOST pointed at the replica, for a full day and
# again across the Tuesday DOE import and the Monday 02:00 forecast:
#
#   SAMPLE_SECONDS=86400 infra/scripts/replica-soak.sh | tee soak-$(date +%F).log
#
# Exits non-zero if any threshold in docs/06 is breached, so it can gate a
# rollout from CI or cron.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

COMPOSE="${COMPOSE:-docker compose -f infra/docker-compose.yml --env-file .env}"
# How to reach the replica's mysql client. Defaults to a compose service; point
# REPLICA_SQL at a managed or external replica instead, e.g.
#   REPLICA_SQL="mysql -h replica.internal -uroot -p$DB_ROOT_PASSWORD"
SAMPLE_SECONDS="${SAMPLE_SECONDS:-3600}"
INTERVAL="${INTERVAL:-10}"
# docs/06: sustained lag above this is a stop condition.
LAG_THRESHOLD="${LAG_THRESHOLD:-1}"
REPLICA_SERVICE="${REPLICA_SERVICE:-mysql-replica}"

say() { printf '%s  %s\n' "$(date -u +%H:%M:%S)" "$*"; }

REPLICA_SQL="${REPLICA_SQL:-$COMPOSE exec -T $REPLICA_SERVICE mysql -uroot -p${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD must be set}}"

lag_now() {
    # Seconds_Behind_Source is NULL when the replica is not applying at all,
    # which must read as a failure rather than as zero lag.
    $REPLICA_SQL -e 'SHOW REPLICA STATUS\G' 2>/dev/null \
        | awk -F': ' '/Seconds_Behind_Source/ {gsub(/ /,"",$2); print ($2=="NULL" ? "-1" : $2)}'
}

say "sampling ${SAMPLE_SECONDS}s every ${INTERVAL}s; stop threshold ${LAG_THRESHOLD}s"

samples=0 breaches=0 max=0 sum=0 stopped=0
deadline=$(( $(date +%s) + SAMPLE_SECONDS ))

while [ "$(date +%s)" -lt "$deadline" ]; do
    lag="$(lag_now || true)"

    if [ -z "$lag" ] || [ "$lag" = "-1" ]; then
        stopped=$((stopped + 1))
        say "REPLICA NOT REPLICATING (Seconds_Behind_Source is NULL)"
    else
        samples=$((samples + 1))
        sum=$((sum + lag))
        [ "$lag" -gt "$max" ] && max="$lag"
        if [ "$lag" -gt "$LAG_THRESHOLD" ]; then
            breaches=$((breaches + 1))
            say "lag ${lag}s  (over the ${LAG_THRESHOLD}s threshold)"
        fi
    fi

    sleep "$INTERVAL"
done

echo
mean=0
[ "$samples" -gt 0 ] && mean=$(( sum / samples ))
say "samples=$samples  max=${max}s  mean=${mean}s"
say "over-threshold=$breaches  not-replicating=$stopped"

# docs/06 stop conditions: sustained lag, or a replica that is not replicating.
if [ "$stopped" -gt 0 ]; then
    say "STOP: the replica stopped replicating during the soak"
    exit 1
fi

if [ "$samples" -eq 0 ]; then
    say "STOP: no samples collected — check REPLICA_SERVICE and credentials"
    exit 1
fi

# A tenth of samples over threshold is sawtoothing, not a blip.
if [ "$breaches" -gt $(( samples / 10 )) ]; then
    say "STOP: lag exceeded ${LAG_THRESHOLD}s in $breaches of $samples samples"
    exit 1
fi

say "PASS: lag stayed within ${LAG_THRESHOLD}s for the sampled window"
say "Still to check by hand (docs/06 §6.8): read-after-write from a client,"
say "replica failover, and replica CPU/IO while analytics run."

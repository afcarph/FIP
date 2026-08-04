#!/bin/sh
#
# Point the staging replica at the primary and start replication.
#
# Run as a one-shot compose service (see infra/docker-compose.staging.yml). It
# lives in a file rather than inline in the compose entrypoint because YAML's
# folded scalars silently reflow shell: an earlier inline version died with
# "syntax error near unexpected token `||`", which meant the check meant to
# catch a failed setup never ran at all.
#
# Idempotent: if replication is already running it exits without touching it.
#
# Required: DB_ROOT_PASSWORD, DB_REPL_PASSWORD. Optional: DB_REPL_USER,
# PRIMARY_HOST, REPLICA_HOST.

set -eu

PRIMARY_HOST="${PRIMARY_HOST:-mysql}"
REPLICA_HOST="${REPLICA_HOST:-mysql-replica}"
REPL_USER="${DB_REPL_USER:-repl}"

: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD must be set}"
: "${DB_REPL_PASSWORD:?DB_REPL_PASSWORD must be set}"

primary() { mysql -h "$PRIMARY_HOST" -uroot -p"$DB_ROOT_PASSWORD" "$@"; }
replica() { mysql -h "$REPLICA_HOST" -uroot -p"$DB_ROOT_PASSWORD" "$@"; }

# compose healthchecks gate on the server accepting connections, but the
# container can still be refusing them for a moment after that. Retry rather
# than fail the whole stack on a race.
wait_for() {
    host="$1"
    i=0
    while [ "$i" -lt 30 ]; do
        if mysql -h "$host" -uroot -p"$DB_ROOT_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; then
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done
    echo "FAILED: $host did not accept connections within 60s" >&2
    return 1
}

replication_running() {
    replica -e 'SHOW REPLICA STATUS\G' 2>/dev/null | grep -q 'Replica_IO_Running: Yes'
}

wait_for "$PRIMARY_HOST"
wait_for "$REPLICA_HOST"

if replication_running; then
    echo "Replication already running"
    exit 0
fi

primary -e "
    CREATE USER IF NOT EXISTS '${REPL_USER}'@'%' IDENTIFIED BY '${DB_REPL_PASSWORD}';
    GRANT REPLICATION SLAVE ON *.* TO '${REPL_USER}'@'%';
    FLUSH PRIVILEGES;
"

replica -e "
    STOP REPLICA;
    CHANGE REPLICATION SOURCE TO
        SOURCE_HOST='${PRIMARY_HOST}',
        SOURCE_USER='${REPL_USER}',
        SOURCE_PASSWORD='${DB_REPL_PASSWORD}',
        SOURCE_AUTO_POSITION=1,
        GET_SOURCE_PUBLIC_KEY=1;
    START REPLICA;
"

# Confirm rather than assume. The applier starts asynchronously, so give it a
# moment before deciding it failed.
i=0
while [ "$i" -lt 15 ]; do
    if replication_running; then
        # Applied here, not as a startup flag: --super-read-only=ON blocks the
        # entrypoint's own bootstrap (creating root, setting its password) and
        # leaves a database nobody can log into. SET PERSIST survives restarts,
        # and replication applier threads are exempt from it.
        replica -e 'SET PERSIST super_read_only = ON'
        echo "Replication configured; replica is super_read_only"
        exit 0
    fi
    i=$((i + 1))
    sleep 2
done

echo "FAILED: replication did not start" >&2
replica -e 'SHOW REPLICA STATUS\G' 2>/dev/null | grep -E 'Last_IO_Error|Last_SQL_Error' >&2 || true
exit 1

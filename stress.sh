#!/usr/bin/env sh
#
# Run the psr-http example server and stress it with siege.
#
# Environment:
#   SERVER_IMAGE  image built from run.dockerfile   (default: psr-http-run)
#   SIEGE_IMAGE   image built from siege.dockerfile (default: psr-http-siege)
#   CONCURRENCY   concurrent siege clients          (default: 250)
#   DURATION      siege run time                    (default: 60S)
#   URL           target URL                        (default: http://<server>:8000/)
#   READY_TIMEOUT seconds to wait for the server     (default: 15)
#
# Extra arguments are passed through to siege, for example:
#
#   ./stress.sh -k
set -eu

SERVER_IMAGE=${SERVER_IMAGE:-psr-http-run}
SIEGE_IMAGE=${SIEGE_IMAGE:-psr-http-siege}
CONCURRENCY=${CONCURRENCY:-250}
DURATION=${DURATION:-60S}
READY_TIMEOUT=${READY_TIMEOUT:-15}
NETWORK=${NETWORK:-psr-http-stress-$$}
SERVER_NAME=${SERVER_NAME:-psr-http-server-$$}
URL=${URL:-http://$SERVER_NAME:8000/}
report=

server_started=0
network_created=0

cleanup() {
    status=$?
    if [ -n "$report" ]; then
        rm -f "$report"
    fi
    if [ "$server_started" -eq 1 ]; then
        docker rm -f "$SERVER_NAME" >/dev/null 2>&1 || true
    fi
    if [ "$network_created" -eq 1 ]; then
        docker network rm "$NETWORK" >/dev/null 2>&1 || true
    fi
    exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if docker network inspect "$NETWORK" >/dev/null 2>&1; then
    echo "Reusing network $NETWORK"
else
    docker network create "$NETWORK" >/dev/null
    network_created=1
fi

echo "Starting $SERVER_IMAGE as $SERVER_NAME on $NETWORK"
docker run -d --rm --name "$SERVER_NAME" --network "$NETWORK" "$SERVER_IMAGE" >/dev/null
server_started=1

echo "Waiting for the server to accept connections"
waited=0
until docker exec "$SERVER_NAME" php -r 'exit(@fsockopen("127.0.0.1", 8000) ? 0 : 1);' >/dev/null 2>&1; do
    if [ "$(docker inspect -f '{{.State.Running}}' "$SERVER_NAME" 2>/dev/null || echo false)" != "true" ]; then
        echo "Server exited before accepting connections:" >&2
        docker logs "$SERVER_NAME" >&2 || true
        exit 1
    fi
    waited=$((waited + 1))
    if [ "$waited" -ge $((READY_TIMEOUT * 5)) ]; then
        echo "Server did not accept connections within ${READY_TIMEOUT}s:" >&2
        docker logs "$SERVER_NAME" >&2 || true
        exit 1
    fi
    sleep 0.2
done

echo "siege -b -j -c $CONCURRENCY -t $DURATION $* $URL"
report=$(mktemp "${TMPDIR:-/tmp}/psr-http-siege.XXXXXX")
set +e
# shellcheck disable=SC2086
docker run --rm --network "$NETWORK" "$SIEGE_IMAGE" -b -j -c "$CONCURRENCY" -t "$DURATION" "$@" "$URL" \
    >"$report" 2>&1
status=$?
set -e

cat "$report"

# Siege exits 0 even when every transaction failed, so inspect its report.
failed=$(sed -n 's/.*"failed_transactions"[^0-9]*\([0-9][0-9]*\).*/\1/p' "$report" | tail -n 1)
successful=$(sed -n 's/.*"successful_transactions"[^0-9]*\([0-9][0-9]*\).*/\1/p' "$report" | tail -n 1)

if [ -z "$failed" ] || [ -z "$successful" ]; then
    echo "Could not parse the siege report" >&2
    docker logs --tail 40 "$SERVER_NAME" >&2 || true
    exit 1
fi

echo "siege: $successful successful and $failed failed transactions"

if [ "$failed" -ne 0 ] || [ "$successful" -eq 0 ]; then
    echo "siege reported failures; last server log lines:" >&2
    docker logs --tail 40 "$SERVER_NAME" >&2 || true
    exit 1
fi

exit "$status"

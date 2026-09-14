#!/usr/bin/env bash
# Orchestrates the k6 load test against the production stack (docs/conception.md §9.4).
# Always uses `docker compose -f compose.yaml` (never the dev override).
#
# Usage:
#   tests/Load/run-load-test.sh              # SCENARIO=nominal (blocking thresholds)
#   SCENARIO=worst|ramp|contention|quotas tests/Load/run-load-test.sh
#
# Env:
#   LOAD_BASE_URL   default http://nginx:8080 (k6 on the compose network)
#   LOAD_KEEP_STACK if 1, leave raised quotas in place (default: recreate nginx with prod quotas)
#   K6_IMAGE        default grafana/k6:1.3.0
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

COMPOSE="${COMPOSE:-docker compose -f compose.yaml}"
SCENARIO="${SCENARIO:-nominal}"
K6_IMAGE="${K6_IMAGE:-grafana/k6:1.3.0}"
LOAD_BASE_URL="${LOAD_BASE_URL:-http://nginx:8080}"
NETWORK_NAME="${NETWORK_NAME:-}"
RESULTS_DIR="${RESULTS_DIR:-$ROOT/var/load-test}"
mkdir -p "$RESULTS_DIR"

# Raised only for this test so 10 req/s (and modest ramps) are not rejected by Nginx (§9.4).
RAISED_RATE_LIMIT_PER_IP="${RAISED_RATE_LIMIT_PER_IP:-100r/s}"
RAISED_RATE_LIMIT_PER_IP_BURST="${RAISED_RATE_LIMIT_PER_IP_BURST:-100}"
RAISED_RATE_LIMIT_GLOBAL="${RAISED_RATE_LIMIT_GLOBAL:-100r/s}"
RAISED_RATE_LIMIT_GLOBAL_BURST="${RAISED_RATE_LIMIT_GLOBAL_BURST:-100}"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
summary_file="$RESULTS_DIR/${stamp}-${SCENARIO}.txt"
json_file="$RESULTS_DIR/${stamp}-${SCENARIO}.json"
LOCK_PID=
SAMPLER_PID=

log() { printf '%s\n' "$*"; }
die() { printf '%s\n' "$*" >&2; exit 1; }

cleanup() {
    if [[ -n "${SAMPLER_PID}" ]]; then
        kill "$SAMPLER_PID" 2>/dev/null || true
        wait "$SAMPLER_PID" 2>/dev/null || true
    fi
    if [[ -n "${LOCK_PID}" ]]; then
        kill "$LOCK_PID" 2>/dev/null || true
        wait "$LOCK_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

compose_network() {
    if [[ -n "$NETWORK_NAME" ]]; then
        printf '%s' "$NETWORK_NAME"
        return
    fi
    local id
    id="$($COMPOSE ps -q nginx 2>/dev/null | head -n1 || true)"
    [[ -n "$id" ]] || die "nginx container not found; start the prod stack first."
    docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' "$id"
}

assert_prod() {
    local app_env
    app_env="$($COMPOSE exec -T php printenv APP_ENV 2>/dev/null || true)"
    [[ "$app_env" == "prod" ]] || die "Stack is not prod (APP_ENV « ${app_env} »). Use: docker compose -f compose.yaml up -d --wait --wait-timeout 60"
}

up_with_quotas() {
    local per_ip="$1" per_ip_burst="$2" global="$3" global_burst="$4"
    log "Starting prod stack with RATE_LIMIT_PER_IP=$per_ip burst=$per_ip_burst GLOBAL=$global burst=$global_burst"
    RATE_LIMIT_PER_IP="$per_ip" \
    RATE_LIMIT_PER_IP_BURST="$per_ip_burst" \
    RATE_LIMIT_GLOBAL="$global" \
    RATE_LIMIT_GLOBAL_BURST="$global_burst" \
        $COMPOSE up -d --wait --wait-timeout 60 --force-recreate nginx
    assert_prod
}

restore_prod_quotas() {
    if [[ "${LOAD_KEEP_STACK:-0}" == "1" ]]; then
        log "LOAD_KEEP_STACK=1: leaving current quotas in place."
        return
    fi
    log "Recreating nginx with default (production) RATE_LIMIT_* values."
    $COMPOSE up -d --wait --wait-timeout 60 --force-recreate nginx
}

sample_fpm_memory() {
    local out="$1"
    # RSS of php-fpm pool workers (exclude master), in KiB.
    # The prod image has no `ps`; read /proc. Match only processes whose
    # cmdline starts with "php-fpm: pool " so this sampling shell is excluded.
    $COMPOSE exec -T php sh -c '
for cmdline in /proc/[0-9]*/cmdline; do
  [ -r "$cmdline" ] || continue
  args=$(tr "\0" " " < "$cmdline" 2>/dev/null || true)
  case "$args" in
    "php-fpm: pool "*)
      pid=${cmdline#/proc/}; pid=${pid%/cmdline}
      awk "/^VmRSS:/ { print \$2 }" "/proc/$pid/status" 2>/dev/null || true
      ;;
  esac
done
' > "$out" 2>/dev/null || true
}

db_sizes() {
    $COMPOSE exec -T php sh -c \
        'db=/app/var/data/app.db; wal=/app/var/data/app.db-wal;
         printf "db_bytes="; wc -c < "$db" 2>/dev/null || echo 0;
         printf "wal_bytes="; if [ -f "$wal" ]; then wc -c < "$wal"; else echo 0; fi'
}

run_k6() {
    local network="$1"
    local tmp
    tmp="$(mktemp -d)"
    cp "$ROOT/tests/Load/fizzbuzz.js" "$tmp/fizzbuzz.js"
    set +e
    docker run --rm \
        --network "$network" \
        -v "$tmp:/scripts" \
        -e "BASE_URL=$LOAD_BASE_URL" \
        -e "SCENARIO=$SCENARIO" \
        "$K6_IMAGE" run \
        --summary-export="/scripts/summary.json" \
        /scripts/fizzbuzz.js \
        | tee "$summary_file"
    local k6_rc=${PIPESTATUS[0]}
    set -e
    if [[ -f "$tmp/summary.json" ]]; then
        cp "$tmp/summary.json" "$json_file"
    fi
    rm -rf "$tmp"
    return "$k6_rc"
}

hold_sqlite_lock() {
    local seconds="${1:-55}"
    log "Injecting SQLite write lock (BEGIN IMMEDIATE) for ${seconds}s"
    # Prod rootfs is read_only: cannot docker cp into the image. Feed the holder on stdin.
    $COMPOSE exec -T -e CONTENTION_SECONDS="$seconds" -e DATABASE_PATH=/app/var/data/app.db php \
        php < "$ROOT/tests/Load/hold-sqlite-lock.php" &
    LOCK_PID=$!
}

case "$SCENARIO" in
    nominal|worst|ramp|contention|quotas) ;;
    *) die "Unknown SCENARIO=$SCENARIO (nominal|worst|ramp|contention|quotas)" ;;
esac

log "=== load-test scenario=$SCENARIO ==="

if [[ "$SCENARIO" == "quotas" ]]; then
    up_with_quotas "1r/s" "2" "10r/s" "10"
else
    up_with_quotas \
        "$RAISED_RATE_LIMIT_PER_IP" \
        "$RAISED_RATE_LIMIT_PER_IP_BURST" \
        "$RAISED_RATE_LIMIT_GLOBAL" \
        "$RAISED_RATE_LIMIT_GLOBAL_BURST"
fi

network="$(compose_network)"
log "Compose network: $network"

mem_before="$RESULTS_DIR/${stamp}-${SCENARIO}-rss-before.txt"
mem_during="$RESULTS_DIR/${stamp}-${SCENARIO}-rss-during.txt"
mem_after="$RESULTS_DIR/${stamp}-${SCENARIO}-rss-after.txt"
sample_fpm_memory "$mem_before"

if [[ "$SCENARIO" == "contention" ]]; then
    hold_sqlite_lock 55
    sleep 1
fi

(
    sleep 30
    sample_fpm_memory "$mem_during"
) &
SAMPLER_PID=$!

k6_rc=0
run_k6 "$network" || k6_rc=$?

kill "$SAMPLER_PID" 2>/dev/null || true
wait "$SAMPLER_PID" 2>/dev/null || true
SAMPLER_PID=
sample_fpm_memory "$mem_after"

if [[ "$SCENARIO" == "contention" ]]; then
    wait "$LOCK_PID" 2>/dev/null || true
    LOCK_PID=
fi

{
    echo "=== associated measures ($SCENARIO @ $stamp) ==="
    echo "--- PHP-FPM worker RSS (KiB), before ---"
    cat "$mem_before" || true
    echo "--- PHP-FPM worker RSS (KiB), ~30s ---"
    cat "$mem_during" 2>/dev/null || echo "(no mid-run sample)"
    echo "--- PHP-FPM worker RSS (KiB), after ---"
    cat "$mem_after" || true
    echo "--- SQLite sizes ---"
    db_sizes
} | tee -a "$summary_file"

restore_prod_quotas

log "k6 exit code: $k6_rc"
log "Summary: $summary_file"
[[ -f "$json_file" ]] && log "JSON export: $json_file"
exit "$k6_rc"

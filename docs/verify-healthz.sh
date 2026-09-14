#!/usr/bin/env bash
# Runs the "Operations" folder of docs/FizzBuzz.postman_collection.json via Newman, attached to the
# Compose network, against http://nginx:8080 instead of localhost:8080. From inside that network,
# Nginx sees a trusted address, so /healthz answers 200 — see the collection's own description.
# Always uses the production stack (`docker compose -f compose.yaml`), never the dev override.
#
# Usage: docs/verify-healthz.sh   (or `make postman-healthz`)
# Env:
#   NETWORK_NAME  override the auto-detected Compose network
#   NEWMAN_IMAGE  default postman/newman:6-alpine
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="${COMPOSE:-docker compose -f compose.yaml}"
NEWMAN_IMAGE="${NEWMAN_IMAGE:-postman/newman:6-alpine}"
NETWORK_NAME="${NETWORK_NAME:-}"

die() { printf '%s\n' "$*" >&2; exit 1; }

app_env="$($COMPOSE exec -T php printenv APP_ENV 2>/dev/null || true)"
[[ "$app_env" == "prod" ]] || die "Stack is not prod (APP_ENV « ${app_env} »). Use: docker compose -f compose.yaml up -d --wait --wait-timeout 60"

if [[ -z "$NETWORK_NAME" ]]; then
    nginx_id="$($COMPOSE ps -q nginx 2>/dev/null || true)"
    [[ -n "$nginx_id" ]] || die "nginx container not found; start the prod stack first."
    NETWORK_NAME="$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' "$nginx_id")"
fi

docker run --rm \
    --network "$NETWORK_NAME" \
    -v "$ROOT/docs:/etc/newman" \
    "$NEWMAN_IMAGE" run FizzBuzz.postman_collection.json \
    --folder Operations \
    --env-var baseUrl=http://nginx:8080

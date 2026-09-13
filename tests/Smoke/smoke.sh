#!/usr/bin/env bash
# Smoke test de la stack Docker, via Nginx (docs/conception.md §9.1).
# Étape 3 : /healthz interdit depuis l'hôte (403), 413, 414 avec une vraie URL > 8 Ko,
# quota par IP (429), en-têtes communs. Complété à l'étape 9 (/healthz 200, HEAD, IP simulées, 502, logs, démarrage).
# Usage : BASE_URL=http://127.0.0.1:8080 tests/Smoke/smoke.sh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
failures=0

pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1" >&2; failures=$((failures + 1)); }

# request NOM [arguments curl...] : enregistre NOM.headers et NOM.body, affiche le statut HTTP.
# Une erreur de curl (connexion fermée par Nginx) donne le statut 000, signalé comme un échec normal.
request() {
    local name="$1"
    shift
    : > "$WORK_DIR/$name.headers"
    : > "$WORK_DIR/$name.body"
    curl --noproxy '*' --silent --output "$WORK_DIR/$name.body" --dump-header "$WORK_DIR/$name.headers" \
        --write-out '%{http_code}' "$@" || true
}

# header NOM En-Tête : valeur(s) de l'en-tête, une par ligne.
header() {
    grep -i "^$2:" "$WORK_DIR/$1.headers" | sed -E 's/^[^:]+:[[:space:]]*//; s/\r$//' || true
}

expect_status() { # LIBELLÉ ATTENDU OBTENU
    if [[ "$3" == "$2" ]]; then pass "$1 : statut $2"; else fail "$1 : statut $3, attendu $2"; fi
}

expect_common_headers() { # NOM
    local cache_control request_id nosniff
    cache_control="$(header "$1" Cache-Control)"
    request_id="$(header "$1" X-Request-Id)"
    nosniff="$(header "$1" X-Content-Type-Options)"
    if [[ "$cache_control" == "no-store" ]]; then pass "$1 : Cache-Control no-store, valeur unique"; else fail "$1 : Cache-Control « $cache_control »"; fi
    if [[ -n "$request_id" ]]; then pass "$1 : X-Request-Id présent"; else fail "$1 : X-Request-Id absent"; fi
    if [[ "$nosniff" == "nosniff" ]]; then pass "$1 : X-Content-Type-Options nosniff"; else fail "$1 : X-Content-Type-Options « $nosniff »"; fi
}

expect_problem() { # NOM STATUT TITRE
    local content_type body expected
    content_type="$(header "$1" Content-Type)"
    body="$(cat "$WORK_DIR/$1.body")"
    expected="{\"type\":\"about:blank\",\"title\":\"$3\",\"status\":$2}"
    if [[ "$content_type" == application/problem+json* ]]; then pass "$1 : application/problem+json"; else fail "$1 : Content-Type « $content_type »"; fi
    if [[ "$body" == "$expected" ]]; then pass "$1 : corps problem+json"; else fail "$1 : corps « ${body:0:200} »"; fi
}

if ! curl --noproxy '*' --silent --output /dev/null "$BASE_URL/"; then
    echo "Stack injoignable sur $BASE_URL : lancer make start." >&2
    exit 1
fi

# 403 : /healthz est réservé au réseau d'exploitation ; un X-Forwarded-For venu d'un client non fiable est ignoré.
status="$(request healthz_public -H 'X-Forwarded-For: 203.0.113.10' "$BASE_URL/healthz")"
expect_status "/healthz depuis l'hôte" 403 "$status"
expect_problem healthz_public 403 Forbidden
expect_common_headers healthz_public
status="$(request healthz_spoofed -H 'X-Forwarded-For: 127.0.0.1' "$BASE_URL/healthz")"
expect_status "/healthz avec X-Forwarded-For: 127.0.0.1 forgé" 403 "$status"

# 413 : corps de plus de 1 Ko, rejeté avant PHP et avant les quotas.
head -c 2048 /dev/zero > "$WORK_DIR/payload"
status="$(request payload_too_large -X POST --data-binary "@$WORK_DIR/payload" "$BASE_URL/v1/fizzbuzz")"
expect_status "corps de 2 Ko" 413 "$status"
expect_problem payload_too_large 413 "Payload Too Large"
expect_common_headers payload_too_large

# 414 : vraie URL de plus de 8 Ko ; la réponse doit être du JSON, ni un 500 ni une page HTML.
long_value="$(head -c 9000 /dev/zero | tr '\0' a)"
status="$(request uri_too_long "$BASE_URL/v1/fizzbuzz?str1=$long_value")"
expect_status "URL de plus de 8 Ko" 414 "$status"
expect_problem uri_too_long 414 "URI Too Long"
expect_common_headers uri_too_long

# 429 : 1r/s et burst 2 par IP, soit 3 requêtes simultanées acceptées sur 5.
request warmup "$BASE_URL/v1/fizzbuzz" > /dev/null  # cache Symfony chaud avant les appels parallèles
sleep 3                                             # l'excédent du seau par IP retombe à 0
for i in 1 2 3 4 5; do
    request "burst_$i" "$BASE_URL/v1/fizzbuzz" > "$WORK_DIR/burst_$i.status" &
done
wait
accepted=0
rejected=0
accepted_name=""
rejected_name=""
for i in 1 2 3 4 5; do
    code="$(cat "$WORK_DIR/burst_$i.status")"
    if [[ "$code" == "429" ]]; then
        rejected=$((rejected + 1)); rejected_name="burst_$i"
    elif [[ "$code" =~ ^[1-4][0-9][0-9]$ ]]; then
        accepted=$((accepted + 1)); accepted_name="burst_$i"
    else
        fail "rafale : requête $i en statut « $code »"
    fi
done
if [[ $accepted -eq 3 && $rejected -eq 2 ]]; then pass "rafale de 5 : 3 acceptées, 2 en 429"; else fail "rafale de 5 : $accepted acceptées, $rejected en 429"; fi
if [[ -n "$rejected_name" ]]; then
    expect_problem "$rejected_name" 429 "Too Many Requests"
    expect_common_headers "$rejected_name"
    retry_after="$(header "$rejected_name" Retry-After)"
    if [[ "$retry_after" == "1" ]]; then pass "429 : Retry-After 1"; else fail "429 : Retry-After « $retry_after »"; fi
fi
if [[ -n "$accepted_name" ]]; then
    expect_common_headers "$accepted_name"  # réponse de Symfony : Cache-Control remplacé par Nginx
fi

if (( failures > 0 )); then
    echo "smoke : $failures échec(s)" >&2
    exit 1
fi
echo "smoke : tout est vert"

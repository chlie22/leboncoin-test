#!/usr/bin/env bash
# Smoke test de la stack Docker, via Nginx (docs/conception.md §9.1).
# Étape 3 : /healthz interdit depuis l'hôte (403), 413, 414 avec une vraie URL > 8 Ko, quota par IP (429), en-têtes communs.
# Étape 9 : /healthz 200, HEAD, deux IP simulées, 429 non compté, incident amont, marqueurs dans les logs Nginx, démarrage.
#
# Prérequis : la stack PROD démarrée (make build, puis docker compose -f compose.yaml up -d --wait --wait-timeout 60).
# Destructif : le script arrête PHP, fait des down/up (volumes conservés) et laisse la stack prod démarrée.
# Il refuse de tourner sur la stack dev, qu'il remplacerait sinon par la prod.
# Usage : BASE_URL=http://127.0.0.1:8080 tests/Smoke/smoke.sh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
COMPOSE="${COMPOSE:-docker compose -f compose.yaml}"
FIZZ_QUERY='int1=3&int2=5&limit=15&str1=fizz&str2=buzz'
SMOKE_START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
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

# markers SCÉNARIO : str1 et str2 propres à un scénario, pour attribuer chaque ligne de log à sa requête (R08).
markers() { printf 'str1=MARK_%s_S1&str2=MARK_%s_S2' "$1" "$1"; }

# window_count : window.count de /v1/stats, lu depuis 127.0.0.1 dans le conteneur nginx (hors quota de l'hôte).
window_count() {
    $COMPOSE exec -T nginx wget -q -O - http://127.0.0.1:8080/v1/stats | sed -n 's/.*"count":\([0-9]*\).*/\1/p' || true
}

# internal_status IP CHEMIN : GET depuis le conteneur php (réseau de confiance) avec X-Forwarded-For (§7.4).
internal_status() {
    local xff="$1"
    local path_and_query="$2"
    $COMPOSE exec -T -e "SMOKE_XFF=$xff" -e "SMOKE_PATH=$path_and_query" php \
        php -r '
$xff = getenv("SMOKE_XFF");
$path = getenv("SMOKE_PATH");
$url = "http://nginx:8080".$path;
$ctx = stream_context_create([
    "http" => [
        "method" => "GET",
        "header" => "X-Forwarded-For: ".$xff."\r\nAccept: application/json\r\n",
        "ignore_errors" => true,
        "timeout" => 10,
    ],
]);
$body = @file_get_contents($url, false, $ctx);
$code = "000";
if (isset($http_response_header[0]) && preg_match("/\s(\d{3})\b/", $http_response_header[0], $m) === 1) {
    $code = $m[1];
}
fwrite(STDOUT, $code);
'
}

rate_sleep() { sleep 1; }

if ! curl --noproxy '*' --silent --output /dev/null "$BASE_URL/"; then
    echo "Stack injoignable sur $BASE_URL : lancer make build && docker compose -f compose.yaml up -d --wait --wait-timeout 60." >&2
    exit 1
fi
app_env="$($COMPOSE exec -T php printenv APP_ENV 2>/dev/null || true)"
if [[ "$app_env" != "prod" ]]; then
    echo "La stack démarrée n'est pas la prod (APP_ENV « $app_env ») : le smoke la remplacerait. Lancer make build && docker compose -f compose.yaml up -d --wait --wait-timeout 60." >&2
    exit 1
fi

# 403 : /healthz est réservé au réseau d'exploitation ; un X-Forwarded-For venu d'un client non fiable est ignoré.
status="$(request healthz_public -H 'X-Forwarded-For: 203.0.113.10' "$BASE_URL/healthz?$(markers 403)")"
expect_status "/healthz depuis l'hôte" 403 "$status"
expect_problem healthz_public 403 Forbidden
expect_common_headers healthz_public
status="$(request healthz_spoofed -H 'X-Forwarded-For: 127.0.0.1' "$BASE_URL/healthz")"
expect_status "/healthz avec X-Forwarded-For: 127.0.0.1 forgé" 403 "$status"

# /healthz 200 en local : 127.0.0.1 dans le conteneur nginx est autorisé.
health_body="$($COMPOSE exec -T nginx wget -q -O - http://127.0.0.1:8080/healthz || true)"
if [[ "$health_body" == '{"status":"ok","checks":{"statistics":"ok"}}' ]]; then
    pass "/healthz en local : corps ok"
else
    fail "/healthz en local : corps « ${health_body:0:200} »"
fi

# 413 : corps de plus de 1 Ko, rejeté avant PHP et avant les quotas.
head -c 2048 /dev/zero > "$WORK_DIR/payload"
status="$(request payload_too_large -X POST --data-binary "@$WORK_DIR/payload" "$BASE_URL/v1/fizzbuzz?$(markers 413)")"
expect_status "corps de 2 Ko" 413 "$status"
expect_problem payload_too_large 413 "Payload Too Large"
expect_common_headers payload_too_large

# 414 : vraie URL de plus de 8 Ko ; la réponse doit être du JSON, ni un 500 ni une page HTML.
long_value="$(head -c 9000 /dev/zero | tr '\0' a)"
status="$(request uri_too_long "$BASE_URL/v1/fizzbuzz?str1=$long_value")"
expect_status "URL de plus de 8 Ko" 414 "$status"
expect_problem uri_too_long 414 "URI Too Long"
expect_common_headers uri_too_long

# 200 compté : témoin positif, sans lequel « 429 non compté » ne prouverait rien (fenêtre pleine, lecture cassée).
rate_sleep
rate_sleep
count_before="$(window_count)"
status="$(request fizzbuzz_ok "$BASE_URL/v1/fizzbuzz?int1=3&int2=5&limit=15&$(markers OK)")"
expect_status "GET /v1/fizzbuzz" 200 "$status"
expect_common_headers fizzbuzz_ok
count_after="$(window_count)"
if [[ -n "$count_before" && "$count_after" == "$((count_before + 1))" ]]; then
    pass "200 compté (window.count $count_before → $count_after)"
else
    fail "200 non compté ou stats illisibles : window.count « $count_before » → « $count_after »"
fi

# HEAD sur les 3 endpoints. BusyBox wget ne sait pas envoyer HEAD : /healthz passe par PHP, sur le réseau de confiance.
head_hz="$($COMPOSE exec -T php php -r '
$ctx = stream_context_create(["http" => ["method" => "HEAD", "ignore_errors" => true, "timeout" => 5]]);
@file_get_contents("http://nginx:8080/healthz", false, $ctx);
$code = "000";
if (isset($http_response_header[0]) && preg_match("/\s(\d{3})\b/", $http_response_header[0], $m) === 1) {
    $code = $m[1];
}
$hasBody = false;
foreach ($http_response_header ?? [] as $line) {
    if (stripos($line, "Content-Length:") === 0 && trim(substr($line, 15)) !== "" && trim(substr($line, 15)) !== "0") {
        $hasBody = true;
    }
}
fwrite(STDOUT, $code.($hasBody ? " body" : ""));
')"
if [[ "$head_hz" == "200" ]]; then pass "HEAD /healthz en local : 200 sans corps"; else fail "HEAD /healthz en local : « $head_hz »"; fi

rate_sleep
# -X HEAD et non -I : avec --output, -I peut écrire les en-têtes dans le fichier du corps.
status="$(request head_fizzbuzz -X HEAD "$BASE_URL/v1/fizzbuzz?${FIZZ_QUERY}")"
expect_status "HEAD /v1/fizzbuzz" 200 "$status"
if [[ ! -s "$WORK_DIR/head_fizzbuzz.body" ]]; then pass "HEAD /v1/fizzbuzz : corps vide"; else fail "HEAD /v1/fizzbuzz : corps non vide"; fi
expect_common_headers head_fizzbuzz

rate_sleep
status="$(request head_stats -X HEAD "$BASE_URL/v1/stats")"
expect_status "HEAD /v1/stats" 200 "$status"
if [[ ! -s "$WORK_DIR/head_stats.body" ]]; then pass "HEAD /v1/stats : corps vide"; else fail "HEAD /v1/stats : corps non vide"; fi
expect_common_headers head_stats

# 400 : en-têtes communs sur une erreur de Symfony.
rate_sleep
status="$(request bad_request "$BASE_URL/v1/fizzbuzz?int1=abc&int2=5&limit=15&str1=fizz&str2=buzz")"
expect_status "GET /v1/fizzbuzz invalide" 400 "$status"
expect_common_headers bad_request

# 429 : 1r/s et burst 2 par IP, soit 3 requêtes simultanées acceptées sur 5.
rate_sleep
rate_sleep
request warmup "$BASE_URL/v1/fizzbuzz?${FIZZ_QUERY}" > /dev/null  # cache Symfony chaud avant les appels parallèles
sleep 3                                                           # l'excédent du seau par IP retombe à 0
for i in 1 2 3 4 5; do
    request "burst_$i" "$BASE_URL/v1/fizzbuzz?${FIZZ_QUERY}" > "$WORK_DIR/burst_$i.status" &
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

# Deux IP simulées depuis le réseau de confiance : chacune a son propre quota.
sleep 3
burst_for_ip() { # IP PRÉFIXE
    local ip="$1"
    local prefix="$2"
    local a=0 r=0
    for i in 1 2 3 4 5; do
        internal_status "$ip" "/v1/fizzbuzz?${FIZZ_QUERY}" > "$WORK_DIR/${prefix}_$i.status" &
    done
    wait
    for i in 1 2 3 4 5; do
        code="$(cat "$WORK_DIR/${prefix}_$i.status")"
        if [[ "$code" == "429" ]]; then r=$((r + 1))
        elif [[ "$code" =~ ^[1-4][0-9][0-9]$ ]]; then a=$((a + 1))
        else fail "IP $ip : requête $i statut « $code »"
        fi
    done
    if [[ $a -eq 3 && $r -eq 2 ]]; then pass "IP $ip : 3 acceptées, 2 en 429"; else fail "IP $ip : $a acceptées, $r en 429"; fi
}
burst_for_ip "203.0.113.10" "ip_a"
sleep 3
burst_for_ip "203.0.113.11" "ip_b"

# 429 non compté : window.count inchangé (une autre combinaison que le gagnant ne changerait pas ses hits) ;
# /healthz reste hors quota pendant la saturation.
sleep 3
for i in 1 2 3; do
    request "quota_fill_$i" "$BASE_URL/v1/fizzbuzz?int1=7&int2=11&limit=1&str1=x&str2=y" > /dev/null
done
count_before="$(window_count)"
status="$(request quota_429 "$BASE_URL/v1/fizzbuzz?int1=7&int2=11&limit=1&$(markers 429)")"
expect_status "quota saturé" 429 "$status"
count_after="$(window_count)"
if [[ -n "$count_before" && "$count_before" == "$count_after" ]]; then
    pass "429 non compté (window.count inchangé : $count_after)"
else
    fail "429 compté ou stats illisibles : window.count « $count_before » → « $count_after »"
fi
hz_during="$($COMPOSE exec -T nginx wget -q -O - http://127.0.0.1:8080/healthz || true)"
if [[ "$hz_during" == '{"status":"ok","checks":{"statistics":"ok"}}' ]]; then
    pass "/healthz hors quota pendant saturation"
else
    fail "/healthz pendant saturation : « ${hz_during:0:120} »"
fi

# Incident amont : PHP arrêté. Nginx garde l'adresse résolue au démarrage : selon la plateforme, la connexion
# est refusée (502) ou n'aboutit pas avant fastcgi_connect_timeout (504) ; les deux sont au contrat (§4.4).
php_cid="$($COMPOSE ps -q php)"
docker update --restart=no "$php_cid" >/dev/null
docker stop -t 0 "$php_cid" >/dev/null
sleep 0.5
status="$(request upstream_down --max-time 15 "$BASE_URL/v1/fizzbuzz?int1=3&int2=5&limit=1&$(markers UPSTREAM)")"
if [[ "$status" == "502" ]]; then
    expect_status "upstream down" 502 "$status"
    expect_problem upstream_down 502 "Bad Gateway"
elif [[ "$status" == "504" ]]; then
    expect_status "upstream down" 504 "$status"
    expect_problem upstream_down 504 "Gateway Timeout"
else
    fail "upstream down : statut $status, attendu 502 ou 504"
fi
expect_common_headers upstream_down

# Logs Nginx de ce smoke, flux séparés : log d'accès sur stdout, log d'erreur sur stderr (§8.1, R08).
sleep 1
nginx_cid="$($COMPOSE ps -q nginx)"
docker logs --since "$SMOKE_START" "$nginx_cid" > "$WORK_DIR/nginx-access.log" 2> "$WORK_DIR/nginx-error.log"
docker update --restart=unless-stopped "$php_cid" >/dev/null
$COMPOSE up -d --wait --wait-timeout 60 >/dev/null

has_log() { grep -Fq -- "$2" "$WORK_DIR/nginx-$1.log"; } # access|error MOTIF
if grep -Fq 'MARK_' "$WORK_DIR/nginx-access.log"; then
    fail "marqueurs présents dans le log d'accès Nginx"
else
    pass "aucun marqueur dans le log d'accès Nginx (403, 413, 200, 429, incident amont)"
fi
for scenario in OK 429; do
    if has_log error "MARK_${scenario}_"; then
        fail "marqueurs $scenario présents dans le log d'erreur Nginx"
    else
        pass "marqueurs $scenario absents du log d'erreur Nginx"
    fi
done
for scenario in 403 413 UPSTREAM; do
    if has_log error "MARK_${scenario}_S1" && has_log error "MARK_${scenario}_S2"; then
        pass "marqueurs $scenario présents dans le log d'erreur Nginx"
    else
        fail "marqueurs $scenario absents du log d'erreur Nginx"
    fi
done
if has_log error 'access forbidden by rule'; then pass "403 journalisé dans le log d'erreur Nginx"; else fail "403 absent du log d'erreur Nginx"; fi
if has_log error 'client intended to send too large body'; then pass "413 journalisé dans le log d'erreur Nginx"; else fail "413 absent du log d'erreur Nginx"; fi

# Démarrage : volume de données en lecture seule (R05).
$COMPOSE down >/dev/null 2>&1 || true
mkdir -p "$WORK_DIR/ro-data"
chmod 555 "$WORK_DIR/ro-data"
cat > "$WORK_DIR/ro-override.yaml" <<EOF
services:
  php:
    volumes:
      - type: bind
        source: $WORK_DIR/ro-data
        target: /app/var/data
        read_only: true
EOF
set +e
$COMPOSE -f "$WORK_DIR/ro-override.yaml" up -d --wait --wait-timeout 60 >"$WORK_DIR/ro-up.log" 2>&1
ro_exit=$?
set -e
if [[ "$ro_exit" -ne 0 ]]; then
    pass "démarrage volume lecture seule : up --wait code $ro_exit"
else
    fail "démarrage volume lecture seule : up --wait a réussi (attendu ≠ 0)"
fi
$COMPOSE -f "$WORK_DIR/ro-override.yaml" down >/dev/null 2>&1 || true

# Démarrage : STATS_WINDOW_SIZE invalide ; PHP boucle après les migrations, seul le HEALTHCHECK Nginx le révèle.
set +e
STATS_WINDOW_SIZE=1.5 $COMPOSE up -d --wait --wait-timeout 60 >"$WORK_DIR/bad-window.log" 2>&1
win_exit=$?
set -e
if [[ "$win_exit" -ne 0 ]]; then
    pass "STATS_WINDOW_SIZE=1.5 : up --wait code $win_exit"
else
    fail "STATS_WINDOW_SIZE=1.5 : up --wait a réussi (attendu ≠ 0)"
fi
$COMPOSE down >/dev/null 2>&1 || true

# Stack prod saine en fin de script : le job CI en dump l'état et les logs si le smoke échoue.
$COMPOSE up -d --wait --wait-timeout 60 >/dev/null

if (( failures > 0 )); then
    echo "smoke : $failures échec(s)" >&2
    exit 1
fi
echo "smoke : tout est vert"

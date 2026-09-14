# FizzBuzz API

Test technique leboncoin : API REST FizzBuzz avec statistiques (PHP 8.5, Symfony 8.1, SQLite, Nginx + PHP-FPM, Docker).

> Le runbook complet (démarrage, diagnostics, quotas, sauvegarde) arrive à l’étape 10. Ci-dessous : livrable du test de charge (§9.4).

## Test de charge (k6)

Objectif : démontrer la latence **p95 < 50 ms** à **10 req/s** pour `limit ≤ 100`, avec un taux d’échec < 1 %.

### Exécution

```bash
make build
make load-test                          # scénario nominal (seuils bloquants)
SCENARIO=worst|ramp|contention|quotas make load-test
```

`make load-test` construit l’image **prod**, démarre `docker compose -f compose.yaml` avec des `RATE_LIMIT_*` relevés (sauf `quotas`), lance [grafana/k6:1.3.0](https://hub.docker.com/r/grafana/k6) sur le réseau Compose contre `http://nginx:8080`, puis recrée Nginx avec les quotas de production. La stack **dev** est refusée (`APP_ENV` doit être `prod`).

En CI : job `load-test` du workflow `CI`, déclenché uniquement en `workflow_dispatch`.

### Machine de mesure (local)

| Élément | Valeur |
|---|---|
| Machine | Apple M2 Pro, macOS 26.6.2, arm64 |
| Docker | 29.4.0 / Compose v2.40.3-desktop.1 |
| Image k6 | `grafana/k6:1.3.0` |
| Stack | prod (`compose.yaml`), OPcache + preload |
| Date | 2026-09-14 |

### Scénario nominal (seuils bloquants)

10 req/s constants pendant 2 min, paramètres variés, `limit ≤ 100`. Quotas relevés à 100 r/s.

| Métrique | Valeur | Seuil |
|---|---|---|
| p50 (médiane) | **8,45 ms** | — |
| p95 | **11,51 ms** | < 50 ms ✓ |
| p99 | **15,52 ms** | — |
| Débit | **10,00 req/s** | — |
| Échecs HTTP | **0 %** | < 1 % ✓ |
| Itérations | 1200 | — |
| Code de sortie k6 | **0** | — |

Preuve : `var/load-test/20260914T140547Z-nominal.json` (sortie locale, hors git).

### Scénarios complémentaires (hors seuils bloquants)

| Scénario | p50 | p95 | p99 | Débit | Échecs | Notes |
|---|---|---|---|---|---|---|
| **worst** (`limit=10000`, chaînes 50 car.) | 10,93 ms | 13,96 ms | 16,69 ms | 2,00 req/s | 0 % | ~38 Mo reçus / 1 min |
| **ramp** (10→20→40→60 req/s) | 4,41 ms | 10,65 ms | 14,65 ms | 27,22 req/s moy. | 0 % | max observé 339 ms ; pas de saturation nette sous 60 req/s sur cette machine |
| **contention** (verrou `BEGIN IMMEDIATE` 55 s) | 227,72 ms | 245,82 ms | 251,09 ms | 10,02 req/s | 0 % | 100 % de 200 ; ~537 `statistics.record_skipped` (`LockWaitTimeoutException`) ; max ~3 VU ≈ hypothèse §5.10 |
| **quotas** (prod : 1 r/s/IP, rafale 2) | — | — | — | 5,04 req/s | 77 % (429) | **23×200** et **78×429** sur 20 s — conforme au seau percé (rafale 3 puis ~1/s) |

### Mesures associées (nominal)

| Mesure | Valeur |
|---|---|
| RSS workers PHP-FPM (pendant le test) | ~27–30 MiB / processus (`pm.max_children=8` conservé : suffisant au débit admis 10 req/s) |
| Taille `app.db` après nominal | 241 664 octets |
| Fichier WAL après nominal | 0 octet (checkpointé) |

### Quotas relevés (load-test hors `quotas`)

`RATE_LIMIT_PER_IP=100r/s`, `RATE_LIMIT_PER_IP_BURST=100`, `RATE_LIMIT_GLOBAL=100r/s`, `RATE_LIMIT_GLOBAL_BURST=100`.

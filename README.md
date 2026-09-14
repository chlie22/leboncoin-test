# FizzBuzz API

Test technique leboncoin : API REST FizzBuzz avec statistiques (PHP 8.5, Symfony 8.1, SQLite, Nginx + PHP-FPM, Docker).

Spécification technique : [`docs/conception.md`](docs/conception.md). Contrat d'API (fait foi) : [`docs/openapi.yaml`](docs/openapi.yaml). Avancement : [`docs/progress.md`](docs/progress.md).

## Lancement

**Prérequis** : Docker Compose ; pour la stack **dev**, `vendor/` déjà présent sur l'hôte (`make install` / `composer install`) — `make start` ne reconstruit pas l'image et monte le code.

```bash
make install   # une fois, sur l'hôte
make start     # docker compose up -d --wait (cible dev via compose.override.yaml)
make stop      # docker compose down ; les volumes de données sont conservés
```

- Stack **dev** (défaut) : `make start` — code monté, image cible `dev`.
- Stack **prod** : `make build`, puis `docker compose -f compose.yaml up -d --wait --wait-timeout 60`.
- Vérifications locales : `make ci` (hôte, comme la CI). Smoke et charge exigent la prod : `make smoke`, `make load-test`.
- Liste des cibles : `make help`.

## Contrat d'API (résumé)

Référence : [`docs/openapi.yaml`](docs/openapi.yaml). En cas d'écart, le contrat prime.

| Endpoint | Rôle |
|---|---|
| `GET /v1/fizzbuzz` | Suite FizzBuzz paramétrable (`int1`, `int2`, `limit`, `str1`, `str2`) ; chaque appel réussi est comptabilisé |
| `GET /v1/stats` | Combinaison la plus fréquente **dans la fenêtre glissante** des N derniers appels (`hits`, `window`) |
| `GET /healthz` | Sonde : `ok` ou `degraded` ; réservée au réseau d'exploitation (403 depuis l'extérieur) |

`HEAD` est supporté sur les trois chemins. Erreurs de paramètres : **400** avec la liste des violations (`application/problem+json`). Corrélation : en-tête `X-Request-Id` sur chaque réponse.

**`/v1/stats` est public** (choix documenté) : ne jamais y faire passer de données sensibles via `str1` / `str2`.

## Décisions clés

Détail et décisions validées : [`docs/conception.md`](docs/conception.md) §2 et §13.

- **Architecture** : DDD + hexagonale + SOLID, dépendances vérifiées par Deptrac (D7).
- **Persistance** : Doctrine DBAL + Migrations, **sans ORM** ; SQLite sur volume (D2, D11).
- **Statistiques** : fenêtre glissante des **N** derniers appels (`STATS_WINDOW_SIZE`, défaut 100 000), pas l'historique complet (D8).
- **Mode dégradé** : une fois démarré, un échec d'enregistrement des stats n'empêche pas de servir la génération ; au démarrage, SQLite inaccessible fait échouer le conteneur (D15, D16).
- **Rate limiting** : côté Nginx, avant PHP — 1 req/s par IP (rafale 3) et 10 req/s au global (D10).
- **Entrées HTTP** : `#[MapQueryString]` + DTO + Validator ; erreurs RFC 9457 (D12, D13).
- **Observabilité** : logs JSON corrélés + `/healthz` ; paramètres absents des logs d'accès et applicatifs (D6, §8.1).

## Limites connues

Voir aussi [`docs/conception.md`](docs/conception.md) §6.8, §9.4, §16.

- **Une seule instance** : un seul écrivain SQLite à la fois.
- **Pas d'historique exact au-delà de N** ; le volume disque dépend de la longueur des chaînes (budget volume 500 Mo, alerte à 70 %).
- **Mesures** (benchmarks, charge) réalisées sur machine de développement ; mémoire totale d'une requête Symfony / PHP-FPM non mesurée (§1.2).
- **Sauvegarde** : copier seul le fichier principal pendant une écriture n'est pas une sauvegarde (§6.8).
- **Test de charge** : scénario nominal + scénarios complémentaires (§9.4 / section ci-dessous) — pas une campagne de capacité jusqu'à rupture, ni multi-instance (§14.3).
- **Hors périmètre** : TLS (terminé en amont), CORS, Swagger UI (§16).

## Ouvertures

Pour un service critique ou à fort trafic ([`docs/conception.md`](docs/conception.md) §14) :

- **Sécurité** : authentification (clé d'API ou OAuth2), quotas par client, rate limiting multi-instance, scan d'images.
- **Fiabilité** : supervision externe de `/healthz`, métriques Prometheus, traçage OpenTelemetry, Litestream, déploiement orchestré.
- **Performance** : campagne de capacité sur l'infra cible, FrankenPHP worker, streaming JSON.
- **Scalabilité** : stockage client-serveur (nouvel adaptateur du port), historique en complément, *outbox* pour un comptage exact pendant les pannes.
- **Qualité** : validation OpenAPI automatisée, tests de mutation, collection Postman.

## Runbook

Procédures d'exploitation ([`docs/conception.md`](docs/conception.md) §11.3). Les cibles `make` sont listées par `make help`.

| Opération | Procédure |
|---|---|
| Démarrer, arrêter | `make start` / `make stop` (dev). Prod : `docker compose -f compose.yaml up -d --wait --wait-timeout 60` / `docker compose -f compose.yaml down`. |
| Diagnostiquer une requête | Récupérer `X-Request-Id` (réponse client), puis filtrer les logs Nginx et PHP : `make logs` (ou `docker compose logs`) et rechercher cette valeur. |
| Le conteneur PHP ne démarre pas | Lire la sortie de l'entrypoint : migration ou `app:statistics:apply-window` en échec ; vérifier le montage du volume, les droits (`www-data`) et `STATS_WINDOW_SIZE` (entier décimal ≥ 1). Pour diagnostiquer sans lancer FPM : `docker compose -f compose.yaml run --rm php php bin/console …`. Tant que ce n'est pas corrigé, la génération n'est pas servie (D16). |
| Service en `degraded` | `GET /healthz` → `status: degraded`. Lire les `warning` `statistics.record_skipped` ; vérifier l'espace disque et les droits du volume. La génération reste servie pendant l'intervention. |
| Modifier N ou les quotas | Changer `STATS_WINDOW_SIZE` (`.env` / Compose) ou `RATE_LIMIT_*` (`compose.yaml`), puis redéployer. Une réduction de N est appliquée **au démarrage**, avant le trafic (§6.6). |
| Surveiller le stockage | Taille du volume (alerte à 70 %) et du fichier `-wal` sous `var/data/` (dans le conteneur : `/app/var/data/`). Un WAL qui ne diminue pas après une sauvegarde signale une lecture restée ouverte. |
| Réinitialiser les statistiques | `make stats-reset` (confirmation interactive `y`). Prod : `EXEC='docker compose -f compose.yaml exec -T php' make stats-reset`. |
| Sauvegarder la base | Pas de CLI `sqlite3` dans l'image (minimalisme, §7.9) : `VACUUM INTO` via PDO, depuis le conteneur PHP déjà présent. `docker compose -f compose.yaml exec php php -r '(new PDO("sqlite:/app/var/data/app.db"))->exec("VACUUM INTO \"/app/var/data/backup.db\"");'` **hors pic de trafic** ; ne jamais copier le fichier seul pendant une écriture. |
| Consulter le log d'erreur Nginx | Accès restreint : il peut contenir les paramètres des requêtes lors d'un incident amont (502/504) ou d'un rejet 403 / 413 (§8.1). Les 429 n'y apparaissent pas (`limit_req_log_level info`). |

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

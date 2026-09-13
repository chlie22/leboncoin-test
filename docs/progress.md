# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune (étape 7 faite).
- **Prochaine étape** : 8 — API.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main` ; tout est poussé, étape 7 en `af00927`.
- **Dernière mise à jour** : 2026-09-14.

## Avancement du plan

Étapes et critères de fin : `docs/conception.md` §12.

| # | Étape | Statut | Preuve |
|---|---|---|---|
| 0 | Contrat OpenAPI, validation de la spec | ✅ fait | `npx --yes @redocly/cli@2.52.1 lint` : code 0 (relancé le 2026-09-13) ; review R01 à R12 intégrée |
| 1 | Squelette Symfony 8.1, `git init` | ✅ fait | `bin/console about`, `lint:container`, `lint:yaml`, `composer validate --strict` : code 0 (relancés le 2026-09-13) ; commit `8246597` |
| 2 | Outillage qualité : PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit, Makefile | ✅ fait | 2026-09-13 : `make lint`, `make test`, `make ci` : code 0 ; sondes : 9 violations bloquées, 3 cas autorisés passent ; commit `aac3469` |
| 3 | Docker : PHP-FPM, Nginx, compose | ✅ fait | 2026-09-13 : `make start` 0 ; `make smoke` 0 en dev et en prod (403, 413, 414 JSON, 429) ; conteneurs `read_only` ; `make ci` 0 sur l'hôte et dans le conteneur ; commit `9045d1d` |
| 4 | CI GitHub Actions | ✅ fait | 2026-09-13 : runs `34777100094` (`ec154b2`) et `34778415005` (`851a182`) verts, 4 jobs, logs lus ; `actionlint` 0 ; Composer 2.10.3 |
| 5 | Domain : `FizzBuzzParameters`, `FizzBuzzGenerator` | ✅ fait | 2026-09-13 : TDD rouge (2) puis vert (0), 23 tests ; sondes détectées ; `make ci` 0 ; revues sans bloquant ; commit `a99355a`, run `34780267928` vert |
| 6 | Application : port, cas d'usage, mode dégradé, adaptateur en mémoire | ✅ fait | 2026-09-13 : TDD rouge (2) puis vert (0) ; 8 sondes de mutation détectées ; `make ci` 0 (58 tests, 325 assertions) ; revues sans bloquant ; commit `9f6a2da`, run `34782724339` vert |
| 7 | Persistance SQLite, pragmas, commande `apply-window` | ✅ fait | 2026-09-14 : TDD intégration rouge (2) puis vert (0) ; sondes de mutation 7/9 détectées (survivants attendus : `threshold <= 0` équivalent, `BEGIN` au lieu de `BEGIN IMMEDIATE` non observable) ; Deptrac : `\PDO`/`SQLite3` (3 violations) et ORM/Migrations (2 non couverts) code 1 ; `02-fenetre-exactitude.php` 0 (0 écart) ; `actionlint` 0 ; `make build` 0 ; prod `up --wait` 0 + `make smoke` 0 (logs : 2 migrations puis fenêtre, `journal_mode` wal) ; dev (image reconstruite) `make start` 0 + `make smoke` 0 ; volume en lecture seule : `up --wait` 1 ; `STATS_WINDOW_SIZE=1.5` : boucle de redémarrage, message explicite ; revue de plan (4 bloquants corrigés), d'architecture sans bloquant, `/code-review` : 1 défaut (COMMIT en BUSY laissant la transaction ouverte) corrigé en TDD (1 puis 0) ; `make fix` 0 et `make ci` 0 (127 tests, 987 assertions, PHPStan « No errors », Deptrac 0/0) ; commit `af00927`, run `34786638347` vert (4 jobs ; logs lus : `pdo_sqlite` activé, « Migrations de test » jusqu'à `Version20260912000002`, PHPUnit « OK (127 tests, 986 assertions) », audit sans avis, PHPStan « No errors », smoke « tout est vert ») |
| 8 | API : DTO, contrôleurs, `HEAD`, erreurs | ⏳ à faire | — |
| 9 | `/healthz`, logs corrélés, OPcache, smoke test | ⏳ à faire | — |
| 9b | Test de charge k6 | ⏳ à faire | — |
| 10 | README et runbook | ⏳ à faire | — |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

**Étape 8** : API : `GenerateFizzBuzzQuery` (`#[MapQueryString]`), contrôleurs, `HEAD`, encodage JSON, traduction des exceptions ; matrice du §3.3 verte, Deptrac vert.

- Sections : §3.3, §3.5, §4, §5.3, §5.7, §5.8, §9.1 (Fonctionnel) ; rules `http-api.md`, `testing.md` ; `docs/openapi.yaml` fait foi.
- Installer `symfony/browser-kit` ; Infrastructure ne dépend pas de Shared (§5.2) ; routes via `routing.controllers` ; prod en lecture seule : surveiller les écritures dans `var/cache/prod/pools`.
- `tests/Support/SqliteTestDatabase` boote son propre kernel : dans les `WebTestCase`, prendre la connexion de `static::getContainer()` pour `clear()` / `snapshot()`, sinon deux kernels et deux connexions sur `var/test.db` (revue d'architecture).
- `make test-db` avant un `vendor/bin/phpunit` direct ; `STATS_WINDOW_SIZE=5` en test.
- Docker : `make start` ne reconstruit pas l'image (entrypoint copié) ; `docker compose run --rm php php bin/console …` contourne migrations et fenêtre.
- Chaque push sur `main` lance la CI. Lire les sorties avec `rtk proxy`, les codes de sortie avec `$?` sans pipe (zsh). Push en HTTPS.
- Pour plus tard :
  - étape 9 : `HEALTHCHECK` Nginx, qui rend `up --wait` fiable (sans lui, un échec après les migrations passe : code 0 observé avec `STATS_WINDOW_SIZE=1.5`) ; smoke restant (`/healthz` 200, `HEAD`, deux IP, 502, marqueurs de logs dont 413, démarrage) ; preload ; 404 journalisés en `error` avec la query string (§8.1) ; `path` = `/_errors/…` ; `timeout-minutes` du job `docker` ;
  - étape 9b : job `load-test` en `workflow_dispatch` avec son propre groupe `concurrency` ;
  - `composer audit` peut faire rougir la CI sans changement de code ; mettre à jour les SHA des actions volontairement.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` conservé et adapté ; `composer.json` : `"php": "^8.5"` ; `src/Controller/` supprimé.
- **2026-09-13** — Aucune variable locale versionnée (`.env.dev.local`) ; `APP_SECRET` vide accepté ; dépôt privé (D4), fichiers Claude versionnés sauf `CLAUDE.local.md` et `settings.local.json`.
- **2026-09-13** — Cibles `make` sur l'hôte, `EXEC` pour le conteneur ; `make ci` = validate + audit + lint + test ; `.env.test` versionné. §9.3, §11.1.
- **2026-09-13** — Deptrac : couche `Shared`, `PsrLog` limité à `LoggerInterface`, `--fail-on-uncovered`. PHPStan : `public/` et `config/reference.php` exclus. §5.2, §9.2.
- **2026-09-13** — Docker (choix du développeur) : `compose.yaml` prod + `compose.override.yaml` dev ; tags figés ; `unless-stopped`, `--wait-timeout 60` ; réseau `172.30.0.0/24`, `TRUSTED_PROXY_CIDR` = `172.30.0.128/25` ; conteneurs `read_only`. §7.2 à §7.7.
- **2026-09-13** — Nginx : `per_ip` en dernier ; 400 et 404 de Nginx en HTML (exception §4.4) ; **Q15 élargie** aux 403 et 413. §4.4, §7.3 à §7.5, §8.1, §13.
- **2026-09-13** — CI (choix du développeur) : `push` `main`, `pull_request`, `workflow_dispatch` ; actions épinglées par SHA ; `actionlint` local ; Composer 2.10.3 (GHSA-f9f8-rm49-7jv2). §11.2.
- **2026-09-13** — Étapes 5 et 6 (choix du développeur) : pas d'`equals()` ; messages d'erreur entiers seulement ; `psr/log` direct ; `warning` `statistics.record_skipped` avec noms de classes seulement ; fenêtre vide construite par l'adaptateur. §5.10, §6.3, §6.4.
- **2026-09-14** — Étape 7 (choix du développeur) : entrypoint = migrations + `apply-window` seulement pour `php-fpm` ; `make migrate` / `stats-reset` dans le conteneur par défaut ; base de test par `make test-db` + étape CI ; `STATS_WINDOW_SIZE` refusé strictement hors entier décimal ≥ 1 (fabrique, pas `%env(int:)%`). §6.5, §7.6, §7.8, §9.1, §11.1, §11.2, §11.3.
- **2026-09-14** — Étape 7 (technique) : `extra.symfony.docker` à `false` ; DBAL seul, `logging: false` ; transaction écrite à la main (ni `transactional()`, qui masque l'erreur quand SQLite a déjà annulé, ni confiance aveugle en `isTransactionActive()` après un COMMIT en échec : `ROLLBACK` brut) ; traduction par code SQLite primaire (3, 5, 8, 10, 11, 13, 14, 15, 26 ; 6 propagé) ; Deptrac : `^Doctrine.DBAL` et couche `NativeDatabase` interdite. §5.2, §5.10, §6.3, §9.2, §9.3, §10, rules.

## Journal des sessions

- **2026-09-14 (nuit)** — Étape 7 faite : 4 décisions du développeur, plan relu deux fois (disque plein masqué par `transactional()`), TDD par `tdd-implementer` (1er essai coupé par la limite de dépenses), vérifications Docker (image dev à reconstruire), revue d'architecture sans bloquant, `/code-review` : COMMIT en BUSY corrigé en TDD ; docs synchronisées ; `af00927` poussé, run `34786638347` vert. Nombre d'assertions non constant (987 en local, 986 en CI, cause non recherchée). Prochaine étape : 8.
- **2026-09-13 (nuit, suite)** — Étape 6 faite : 4 décisions tranchées, plan relu (1 bloquant corrigé), TDD avec sondes, `make ci` 0, revues sans bloquant. Prochaine étape : 7.
- **2026-09-13 (fin de soirée)** — Étape 5 faite : plan relu puis ajusté, TDD, `make ci` 0, revues sans point. Prochaine étape : 6.
- **2026-09-13 (nuit)** — Étape 4 faite : workflow CI, revues sans bug, run `34777100094` vert. Composer passé en 2.10.3. Prochaine étape : 5.
- **2026-09-13 (soir)** — Étape 3 faite : image PHP multi-stage, Nginx, compose dev/prod, smoke. Q15 élargie. Prochaine étape : 4.

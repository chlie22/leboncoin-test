# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune (étape 6 faite, pas encore committée).
- **Prochaine étape** : 7 — Persistance SQLite.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main` ; étape 5 poussée en `a99355a`, étape 6 dans l'arbre de travail.
- **Dernière mise à jour** : 2026-09-13.

## Avancement du plan

Étapes et critères de fin : `docs/conception.md` §12.

| # | Étape | Statut | Preuve |
|---|---|---|---|
| 0 | Contrat OpenAPI, validation de la spec | ✅ fait | `npx --yes @redocly/cli@2.52.1 lint` : code 0 (relancé le 2026-09-13) ; review R01 à R12 intégrée |
| 1 | Squelette Symfony 8.1, `git init` | ✅ fait | `bin/console about`, `lint:container`, `lint:yaml`, `composer validate --strict` : code 0 (relancés le 2026-09-13) ; commit `8246597` |
| 2 | Outillage qualité : PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit, Makefile | ✅ fait | 2026-09-13 : `make lint`, `make test` et `make ci` : code 0 ; sondes jetables : 9 violations bloquées, 3 cas autorisés passent, un test en échec fait échouer `make test` ; commit `aac3469` |
| 3 | Docker : PHP-FPM, Nginx, compose | ✅ fait | 2026-09-13 : `make start` 0 ; `make smoke` 0 en dev et en prod (`make build` 0, `docker compose -f compose.yaml up -d --wait --wait-timeout 60` 0) : 403 JSON sur `/healthz`, 413, 414 JSON avec une URL de 9 Ko, 429 ; 30 rafales de 5 : 3 acceptées à chaque fois ; conteneurs `read_only` et `unless-stopped` ; PHP en échec au démarrage : `up --wait` code 1 ; `make ci` 0 sur l'hôte et avec `EXEC='docker compose exec -T php'` ; commit `9045d1d` |
| 4 | CI GitHub Actions | ✅ fait | 2026-09-13 : run `34777100094` vert sur `ec154b2` (`gh run watch --exit-status` 0), jobs `quality`, `tests`, `openapi`, `docker` ; logs lus : audit « No security vulnerability advisories found », PHPStan « No errors », smoke « tout est vert » avec les deux 403 ; Deptrac muet en CI, sonde locale `GITHUB_ACTIONS=true` : propre 0, violation 1 avec `::error` ; `actionlint` 1.7.12 0 et `make ci` 0 en local ; Composer 2.10.3 : `make smoke` 0 en dev et en prod, `make ci` 0, `actionlint` 0 ; run `34778415005` vert sur `851a182` (`composer:2.10.3` au build, audit, PHPStan et smoke lus, plus d'avertissement Composer de `setup-php`) |
| 5 | Domain : `FizzBuzzParameters`, `FizzBuzzGenerator` | ✅ fait | 2026-09-13 : TDD rouge puis vert (`FizzBuzzParametersTest` 10 tests, `FizzBuzzGeneratorTest` 13 : code 2 puis 0) ; sondes `'' !== $term` et `$term ?:` : code 1 sur les cas attendus ; `make ci` 0 relancé hors agent (23 tests, 38 assertions, PHPStan « No errors », Deptrac 0 violation, `lint:container` OK) ; aucun `Symfony` dans `src/FizzBuzz/Domain` ni `tests/Unit/FizzBuzz/Domain` ; revues plan (sound), architecture et `/code-review` sans point bloquant ; commit `a99355a`, run `34780267928` vert (4 jobs, PHPUnit « OK (23 tests, 38 assertions) » lu) |
| 6 | Application : port, cas d'usage, mode dégradé, adaptateur en mémoire | ✅ fait | 2026-09-13 : TDD rouge puis vert (`InMemoryRequestStatisticsStoreTest` 24 tests dont 20 du contrat, `GenerateFizzBuzzTest` 7, `GetMostFrequentRequestTest` 4 : code 2 puis 0) ; 8 sondes de mutation (départage, retour d'une combinaison, `==` lâche, éviction avant incrément, double `record()`, nouvelle tentative, paramètres journalisés, `catch (\Throwable)`) : code 1 ; `make fix` 0 et `make ci` 0 relancés hors agent (58 tests, 325 assertions, PHPStan « No errors », Deptrac 0 violation et 0 non couvert, `lint:container` OK) ; revues plan (1 bloquant corrigé), architecture (3 points non bloquants intégrés) et `/code-review` sans point ; commit à faire |
| 7 | Persistance SQLite, pragmas, commande `apply-window` | ⏳ à faire | — |
| 8 | API : DTO, contrôleurs, `HEAD`, erreurs | ⏳ à faire | — |
| 9 | `/healthz`, logs corrélés, OPcache, smoke test | ⏳ à faire | — |
| 9b | Test de charge k6 | ⏳ à faire | — |
| 10 | README et runbook | ⏳ à faire | — |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

**Étape 7** : persistance SQLite : migrations (2 tables + index, WAL), `SqliteRequestStatisticsStore` (SQL exact du §6.3 et du §6.4), `SqliteConnectionPragmas`, `ApplyStatisticsWindowCommand`, alias du port dans `services.yaml` ; contrat, intégration (plans, pannes, réduction de N) et concurrence verts.

- Sections : §6, §7.6, §7.8, §9.1 (lignes Intégration, pannes, concurrence), §10 ; rule `persistence-sqlite.md` ; si le SQL bouge, relancer `02-fenetre-exactitude.php` et `01-stockage.php`.
- `SqliteRequestStatisticsStoreTest` étend `tests/Contract/RequestStatisticsStoreContractTest` (`createStore(int $windowSize)`) et vérifie en plus, sur les tables, les invariants 2 à 5 du §6.3, que le port ne montre pas.
- Le contrat compare avec `assertSame` : l'adaptateur doit rendre des `int` et les chaînes octet pour octet (`testReturnsTheRecordedValuesWithTheirTypes`, jeu « unicode normalisation »).
- Ne traduire en `StatisticsStoreUnavailable` que les pannes attendues (verrou, lecture seule, disque), erreur DBAL en cause : le log n'en garde que `cause_class`, jamais le message.
- `GenerateFizzBuzz` et `GetMostFrequentRequest` portent une erreur d'autowiring dormante (aucun service pour le port) : l'alias de cette étape la lève ; `lint:container` doit rester vert.
- Entrypoint = migrations puis `apply-window` avant `exec` (décider si `docker compose run php …` doit aussi les exécuter) ; `DATABASE_URL` et `STATS_WINDOW_SIZE` dans `compose.yaml` ; `migrations/` dans PHP-CS-Fixer et PHPStan ; couche Deptrac `\PDO` / `\SQLite3` ; `^Doctrine.*` autorise aussi l'ORM ; CI : « migrations de test » dans le job `tests`, `pdo_sqlite` dans `setup-php` si absent.
- Chaque push sur `main` lance la CI (minutes décomptées). Lire les sorties complètes avec `rtk proxy`, et les codes de sortie avec `$?` sans pipe (zsh). Push en HTTPS uniquement.
- Pour plus tard :
  - étape 8 : installer `symfony/browser-kit` ; Infrastructure ne dépend pas de Shared (§5.2) ; routes via `routing.controllers` ; prod en lecture seule : surveiller les écritures dans `var/cache/prod/pools` ;
  - étape 9 : `HEALTHCHECK` Nginx ; smoke restant (`/healthz` 200, `HEAD`, deux IP simulées, 502, marqueurs de logs dont 413, démarrage) ; effet du preload ; en prod, Symfony journalise chaque 404 en `error` avec l'URL et sa query string (Monolog, §8.1) ; le log d'accès affiche `path` = `/_errors/…` après redirection ; vérifier le `timeout-minutes` du job `docker` ;
  - étape 9b : job `load-test` en `workflow_dispatch` avec son propre groupe `concurrency` ;
  - `composer audit` peut faire rougir la CI sans changement de code (nouvel avis, paquet abandonné) ; mettre à jour les SHA des actions volontairement.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` de la recette conservé et adapté ; `composer.json` : `"php": "^8.5"`, `name`, `description` ; `src/Controller/` supprimé (absent du §10).
- **2026-09-13** — Aucune variable d'environnement locale versionnée : `.env.dev` renommé `.env.dev.local` (ignoré) ; `.env` sans secret ; `APP_SECRET` vide accepté.
- **2026-09-13** — Dépôt GitHub privé (D4) ; fichiers Claude versionnés sauf `CLAUDE.local.md` et `.claude/settings.local.json` ; `deny` sur `git push` retiré.
- **2026-09-13** — Cibles `make` sur l'hôte, variable `EXEC` pour le conteneur ; `make ci` = `composer validate --strict` + `composer audit` + `lint` + `test`. `.env.test` versionné avec son `APP_SECRET` de test public. `symfony/browser-kit` reporté à l'étape 8 ; `bin/phpunit` supprimé. §9.3, §11.1.
- **2026-09-13** — Deptrac : couche `Shared`, `PsrLog` limité à `LoggerInterface`, `--fail-on-uncovered`, cache dans `var/`. PHPStan : `public/` et `config/reference.php` exclus ; `ignoreErrors` ciblé pour `Kernel::getAllowedEnvs()`. §5.2, §9.2.
- **2026-09-13** — Docker (choix du développeur) : pas de `HEALTHCHECK` avant l'étape 9 ; `compose.yaml` prod + `compose.override.yaml` dev (volumes `php-var` et `stats-data-dev`, `www-data` à l'UID/GID de l'hôte) ; smoke dès l'étape 3 ; tags figés ; `restart: unless-stopped` et `--wait-timeout 60`. §7.2, §7.6, §7.7, §10, §11.1, §11.2.
- **2026-09-13** — Réseau Compose `172.30.0.0/24`, `TRUSTED_PROXY_CIDR` = `ip_range` `172.30.0.128/25` ; conteneurs `read_only` (tmpfs PHP `/tmp`, `var/share`, `var/log` à l'uid 33 ; Nginx `/tmp`, `conf.d`). §7.4, §7.6, §7.7.
- **2026-09-13** — Nginx : zone `per_ip` en dernier (course sur les zones non finales) ; `large_client_header_buffers 4 8k` ; FPM `access.log = /dev/null`. §7.3, §7.4, §7.5.
- **2026-09-13** — Choix du développeur : 400 (en-tête > 8 Ko) et 404 (`/_errors/*`) de Nginx restent en HTML, exception au §4.4 ; **Q15 élargie** : paramètres tolérés dans le log d'erreur Nginx aussi pour les 403 et 413. §4.4, §7.9, §8.1, §9.1, §11.3, §13, §15.6.
- **2026-09-13** — CI (choix du développeur) : `push` sur `main`, `pull_request` et `workflow_dispatch`, sans filtre de chemins ; actions épinglées par SHA ; push direct sur `main` ; `actionlint` en local via Docker, hors CI et hors Makefile. Jobs parallèles sur `ubuntu-24.04` avec `timeout-minutes` ; `composer audit` après `install` (sans `vendor/`, il réussit à vide) ; commandes dupliquées du Makefile, avec un commentaire croisé. §11.1, §11.2.
- **2026-09-13** — Composer 2.9.5 → 2.10.3 dans le `Dockerfile` et la CI (choix du développeur), pour GHSA-f9f8-rm49-7jv2 (corrigé en 2.9.8) ; en 2.10.3, `composer audit` sans `vendor/` échoue (code 1). Le Composer de l'hôte reste en 2.9.5 (`composer self-update` à la main). §7.2, §7.6, §11.2, rule `nginx-runtime.md`.
- **2026-09-13** — Étape 5 (choix du développeur) : pas de `FizzBuzzParameters::equals()` sans appelant ; message `<champ> must be greater than or equal to 1, got <valeur>.` (entier seulement, jamais `str1`/`str2`) ; ordre de vérification non testé ; `InvalidFizzBuzzParameters extends \InvalidArgumentException` ; `config/services.yaml` inchangé (`lint:container` OK : services privés inutilisés retirés). Spec inchangée.
- **2026-09-13** — Étape 6 (choix du développeur) : `psr/log` déclaré directement ; contexte du `warning` `statistics.record_skipped` = `error_class` + `cause_class` (noms de classes seulement) ; `RequestStatistics` sans contrôle dans le constructeur (le contrat le garantit) ; la fenêtre vide est construite par l'adaptateur (§6.4 corrigé). Invariants 2 à 5 du §6.3 non observables par le port : vérifiés au niveau des tables à l'étape 7 ; `breakDown()` de l'adaptateur en mémoire accepte l'erreur à lever (pas de bouchon hors contrat) ; `services.yaml` exclut `FizzBuzzParameters`, les deux `Exception/` et `Model/`. §5.10, §6.3, §6.4, §9.1, §9.3, §10.

## Journal des sessions

- **2026-09-13 (nuit, suite)** — Étape 6 faite : 4 décisions tranchées (psr/log, contexte du log, modèle sans contrôle, §6.4), plan relu (incrément avant éviction non testé : corrigé), TDD par `tdd-implementer` avec sondes, `make ci` 0 revérifié, revues d'architecture et de code sans bloquant. Prochaine étape : 7.
- **2026-09-13 (fin de soirée)** — Étape 5 faite : plan relu puis ajusté (cas négatifs, `equals()` reporté), TDD par `tdd-implementer`, `make ci` 0 revérifié, revues d'architecture et de code sans point. Pièges de l'étape 6 notés (exclusion des services, `==` lâche). Prochaine étape : 6.
- **2026-09-13 (nuit)** — Étape 4 faite : workflow CI, revue du plan (audit à vide corrigé), revue d'architecture et `/code-review` sans bug ; `ec154b2` et les étapes 2 et 3 poussés ; run `34777100094` vert, logs lus, Deptrac muet expliqué. Composer passé en 2.10.3 (GHSA-f9f8-rm49-7jv2). Prochaine étape : 5.
- **2026-09-13 (soir)** — Étape 3 faite : image PHP multi-stage, Nginx, compose dev/prod, smoke test. Revues du plan et d'architecture intégrées ; course de `limit_req` et tmpfs root corrigés ; Q15 élargie. Docs synchronisées (§4.4, §7, §8.1, §10, §11, §13, §15, `CLAUDE.md`, `AGENTS.md`, rule). Prochaine étape : 4.
- **2026-09-13 (après-midi)** — Étape 2 faite : outils installés et configurés, Makefile, sondes de garde-fous, revues sans point bloquant. `/code-review` reporté à une étape avec du code métier (choix du développeur). Prochaine étape : 3.
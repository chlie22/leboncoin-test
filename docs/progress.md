# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune (étape 3 faite, revue d'architecture sans point bloquant ; `/code-review` et commit à décider par le développeur).
- **Prochaine étape** : 4 — CI GitHub Actions.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main` ; `aac3469` (étape 2) non poussé, à pousser avec l'étape 3.
- **Dernière mise à jour** : 2026-09-13.

## Avancement du plan

Étapes et critères de fin : `docs/conception.md` §12.

| # | Étape | Statut | Preuve |
|---|---|---|---|
| 0 | Contrat OpenAPI, validation de la spec | ✅ fait | `npx --yes @redocly/cli@2.52.1 lint` : code 0 (relancé le 2026-09-13) ; review R01 à R12 intégrée |
| 1 | Squelette Symfony 8.1, `git init` | ✅ fait | `bin/console about`, `lint:container`, `lint:yaml`, `composer validate --strict` : code 0 (relancés le 2026-09-13) ; commit `8246597` |
| 2 | Outillage qualité : PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit, Makefile | ✅ fait | 2026-09-13 : `make lint`, `make test` et `make ci` : code 0 ; sondes jetables : 9 violations bloquées, 3 cas autorisés passent, un test en échec fait échouer `make test` ; commit `aac3469` |
| 3 | Docker : PHP-FPM, Nginx, compose | ✅ fait | 2026-09-13 : `make start` 0 ; `make smoke` 0 en dev et en prod (`make build` 0, `docker compose -f compose.yaml up -d --wait --wait-timeout 60` 0) : 403 JSON sur `/healthz`, 413, 414 JSON avec une URL de 9 Ko, 429 ; 30 rafales de 5 : 3 acceptées à chaque fois ; conteneurs `read_only` et `unless-stopped` ; PHP en échec au démarrage : `up --wait` code 1 ; `make ci` 0 sur l'hôte et avec `EXEC='docker compose exec -T php'` |
| 4 | CI GitHub Actions | ⏳ à faire | — |
| 5 | Domain : `FizzBuzzParameters`, `FizzBuzzGenerator` | ⏳ à faire | — |
| 6 | Application : port, cas d'usage, mode dégradé, adaptateur en mémoire | ⏳ à faire | — |
| 7 | Persistance SQLite, pragmas, commande `apply-window` | ⏳ à faire | — |
| 8 | API : DTO, contrôleurs, `HEAD`, erreurs | ⏳ à faire | — |
| 9 | `/healthz`, logs corrélés, OPcache, smoke test | ⏳ à faire | — |
| 9b | Test de charge k6 | ⏳ à faire | — |
| 10 | README et runbook | ⏳ à faire | — |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

**Étape 4** : pipeline GitHub Actions vert (jobs `quality`, `tests`, `openapi`, `docker` du §11.2).

- Sections de la spec à lire : §11.2, §9.2, §9.3, §7.7 ; rule `nginx-runtime.md`.
- Job `docker` : `docker compose -f compose.yaml build php`, puis `docker compose -f compose.yaml up -d --wait --wait-timeout 60`, puis `tests/Smoke/smoke.sh`. Sans `-f`, `compose.override.yaml` est chargé (cible dev, code monté, pas de `vendor/`).
- Le 403 du smoke vérifie l'hypothèse du §7.4 : sous Linux, le trafic de l'hôte entre par la passerelle `172.30.0.1`, hors de `TRUSTED_PROXY_CIDR`. S'il échoue en CI, revoir le réseau avant de toucher au test.
- Dépôt privé : la CI consomme le quota de minutes. `make test-*` : code 1 tant que leur suite est vide ; `make test` : code 0.
- Lire les sorties complètes avec `rtk proxy`, et les codes de sortie avec `$?` sans pipe (zsh). Push en HTTPS uniquement.
- Pour plus tard :
  - étape 5 : Deptrac n'analyse que `src/` ; les tests « sans Symfony » ne sont pas vérifiés automatiquement ;
  - étape 6 : ajouter `exclude:` à `config/services.yaml` pour `Domain/` et `Application/Model/` (§10) ;
  - étape 7 : entrypoint = migrations puis `apply-window` avant `exec` (décider si `docker compose run php …` doit aussi les exécuter) ; `DATABASE_URL` et `STATS_WINDOW_SIZE` dans `compose.yaml` ; `migrations/` dans PHP-CS-Fixer et PHPStan ; couche Deptrac `\PDO` / `\SQLite3` ; `^Doctrine.*` autorise aussi l'ORM ;
  - étape 8 : installer `symfony/browser-kit` ; Infrastructure ne dépend pas de Shared (§5.2) ; routes via `routing.controllers` ; prod en lecture seule : surveiller les écritures dans `var/cache/prod/pools` ;
  - étape 9 : `HEALTHCHECK` Nginx ; smoke restant (`/healthz` 200, `HEAD`, deux IP simulées, 502, marqueurs de logs dont 413, démarrage) ; effet du preload ; en prod, Symfony journalise chaque 404 en `error` avec l'URL et sa query string (Monolog, §8.1) ; le log d'accès affiche `path` = `/_errors/…` après redirection.

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

## Journal des sessions

- **2026-09-13 (soir)** — Étape 3 faite : image PHP multi-stage, Nginx, compose dev/prod, smoke test. Revues du plan et d'architecture intégrées ; course de `limit_req` et tmpfs root corrigés ; Q15 élargie. Docs synchronisées (§4.4, §7, §8.1, §10, §11, §13, §15, `CLAUDE.md`, `AGENTS.md`, rule). Prochaine étape : 4.
- **2026-09-13 (après-midi)** — Étape 2 faite : outils installés et configurés, Makefile, sondes de garde-fous, revues sans point bloquant. `/code-review` reporté à une étape avec du code métier (choix du développeur). Prochaine étape : 3.
- **2026-09-13** — Dépôt GitHub privé créé, secret de dev sorti du dépôt, premier commit poussé sur `main`. Prochaine étape : 2.
- **2026-09-12 (soir)** — Étape 1 faite : skeleton Symfony 8.1.6, `git init -b main`, revues passées, `AGENTS.md` adapté. Prochaine étape : 2.
- **2026-09-12** — Review R01 à R12 intégrée. `CLAUDE.md`, skills, agents, workflow, rules et suivi en place. Prochaine étape : 1.

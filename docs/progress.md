# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune (étape 2 faite, commit à décider par le développeur).
- **Prochaine étape** : 3 — Docker.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main`.
- **Dernière mise à jour** : 2026-09-13.

## Avancement du plan

Étapes et critères de fin : `docs/conception.md` §12.

| # | Étape | Statut | Preuve |
|---|---|---|---|
| 0 | Contrat OpenAPI, validation de la spec | ✅ fait | `npx --yes @redocly/cli@2.52.1 lint` : code 0 (relancé le 2026-09-13) ; review R01 à R12 intégrée |
| 1 | Squelette Symfony 8.1, `git init` | ✅ fait | `bin/console about`, `lint:container`, `lint:yaml`, `composer validate --strict` : code 0 (relancés le 2026-09-13) ; commit `8246597` |
| 2 | Outillage qualité : PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit, Makefile | ✅ fait | 2026-09-13 : `make lint`, `make test` et `make ci` : code 0 ; sondes jetables via `make lint` : 9 violations bloquées (Deptrac ×7, CS, PHPStan), 3 cas autorisés passent, un test en échec fait échouer `make test` |
| 3 | Docker : PHP-FPM, Nginx, compose | ⏳ à faire | — |
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

**Étape 3** : `make start` ; un 429, un 403 sur `/healthz` et un 414 JSON avec une vraie URL longue sont observables.

- Sections de la spec à lire : §7.2 à §7.8, §8.1, §11.1 (cibles `start`, `stop`, `sh`, `logs`, `build`), rule `nginx-runtime.md`.
- Docker 29.0.1 disponible sur l'hôte. Les cibles existantes tournent sur l'hôte ; dans le conteneur : `EXEC='docker compose exec -T php'`. Vérifier que `make ci EXEC=…` passe aussi dans le conteneur (npx reste sur l'hôte).
- PHPStan lit `var/cache/dev/App_KernelDevDebugContainer.xml`, produit par `cache:warmup` dans `make lint` : ne pas monter un `var/` d'image `prod` sur celui de l'hôte.
- `make test-unit`, `test-integration`, `test-functional` : code 1 tant que leur suite est vide (PHPUnit 13 avec `--testsuite`) ; `make test` : code 0.
- Lire les sorties complètes avec `rtk proxy`, et les codes de sortie avec `$?` sans pipe (zsh). Push en HTTPS uniquement.
- Pour plus tard :
  - étape 4 : dépôt privé, la CI consomme le quota de minutes ;
  - étape 5 : Deptrac n'analyse que `src/` ; les tests « sans Symfony » ne sont pas vérifiés automatiquement (ajouter des couches de test ou l'écrire au §9.2) ;
  - étape 6 : ajouter `exclude:` à `config/services.yaml` pour `Domain/` et `Application/Model/` (§10) ;
  - étape 7 : ajouter `migrations/` aux chemins de PHP-CS-Fixer et PHPStan ; envisager une couche Deptrac `\PDO` / `\SQLite3` réservée à Infrastructure (classes internes ignorées aujourd'hui) ; `^Doctrine.*` autorise aussi l'ORM ;
  - étape 8 : installer `symfony/browser-kit` ; Infrastructure ne peut pas dépendre de Shared (§5.2) ; routes via `routing.controllers`.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` de la recette conservé et adapté ; `composer.json` : `"php": "^8.5"`, `name`, `description` ; `src/Controller/` supprimé (absent du §10).
- **2026-09-13** — Aucune variable d'environnement locale versionnée : `.env.dev` renommé `.env.dev.local` (ignoré) ; `.env` sans secret ; `APP_SECRET` vide accepté.
- **2026-09-13** — Dépôt GitHub privé (D4) ; fichiers Claude versionnés sauf `CLAUDE.local.md` et `.claude/settings.local.json` ; `deny` sur `git push` retiré.
- **2026-09-13** — Cibles `make` exécutées sur l'hôte, variable `EXEC` pour le conteneur (choix du développeur). `make ci` = `composer validate --strict` + `composer audit` + `lint` + `test`. §11.1 mis à jour.
- **2026-09-13** — `.env.test` de la recette versionné avec son `APP_SECRET` de test fixe, valeur publique (choix du développeur).
- **2026-09-13** — `symfony/browser-kit` reporté à l'étape 8 ; `bin/phpunit` de la recette supprimé (absent du §10, `vendor/bin/phpunit` utilisé). §9.3 mis à jour.
- **2026-09-13** — Deptrac : couche `Shared` distincte, `PsrLog` limité à `LoggerInterface`, `--fail-on-uncovered`, cache dans `var/`. §5.2, §9.2 et la référence de `hexagonal-conventions` mis à jour.
- **2026-09-13** — PHPStan : `public/` exclu (point d'entrée runtime, `$context` non typé), `config/reference.php` exclu (généré) ; un `ignoreErrors` ciblé pour `Kernel::getAllowedEnvs()`, faux positif vérifié (`APP_ENV=foo bin/console about` refuse l'environnement). Garde `method_exists` retirée de `tests/bootstrap.php`.

## Journal des sessions

- **2026-09-13 (après-midi)** — Étape 2 faite : outils installés et configurés, Makefile, sondes de garde-fous, revue du plan et revue d'architecture sans point bloquant. Docs synchronisées (`CLAUDE.md`, `AGENTS.md`, §5.2, §9.2, §9.3, §11.1) ; interdiction de push retirée d'`AGENTS.md`. `/code-review` reporté à une étape avec du code métier (choix du développeur). Prochaine étape : 3.
- **2026-09-13** — Dépôt GitHub privé créé, secret de dev sorti du dépôt, premier commit poussé sur `main`. Prochaine étape : 2.
- **2026-09-12 (soir)** — Étape 1 faite : skeleton Symfony 8.1.6, `git init -b main`, revues passées, `AGENTS.md` adapté. Prochaine étape : 2.
- **2026-09-12** — Review R01 à R12 intégrée. `CLAUDE.md`, skills, agents, workflow, rules et suivi en place. Prochaine étape : 1.
- **2026-09-11** — Conception : arbitrages de stack, spec technique et contrat OpenAPI rédigés.

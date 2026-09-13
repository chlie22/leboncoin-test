# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune.
- **Prochaine étape** : 2 — outillage qualité.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main`.
- **Dernière mise à jour** : 2026-09-13.

## Avancement du plan

Étapes et critères de fin : `docs/conception.md` §12.

| # | Étape | Statut | Preuve |
|---|---|---|---|
| 0 | Contrat OpenAPI, validation de la spec | ✅ fait | `npx --yes @redocly/cli@2.52.1 lint` : valide, code 0 (relancé le 2026-09-12) ; review R01 à R12 intégrée |
| 1 | Squelette Symfony 8.1, `git init` | ✅ fait | 2026-09-12 : `bin/console about` (Symfony 8.1.6, PHP 8.5.2), `bin/console lint:container`, `bin/console lint:yaml config --parse-tags` et `composer validate --strict` : code 0 ; dépôt git sur `main` ; premier commit (hash : `git log --reverse | head -1`) |
| 2 | Outillage qualité : PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit, Makefile | ⏳ à faire | — |
| 3 | Docker : PHP-FPM, Nginx, compose | ⏳ à faire | — |
| 4 | CI GitHub Actions | ⏳ à faire | — |
| 5 | Domain : `FizzBuzzParameters`, `FizzBuzzGenerator` | ⏳ à faire | — |
| 6 | Application : port, cas d'usage, mode dégradé, adaptateur en mémoire | ⏳ à faire | — |
| 7 | Persistance SQLite, pragmas, commande `apply-window` | ⏳ à faire | — |
| 8 | API : DTO, contrôleurs, `HEAD`, erreurs | ⏳ à faire | — |
| 9 | `/healthz`, logs corrélés, OPcache, smoke test | ⏳ à faire | — |
| 9b | Test de charge k6 | ⏳ à faire | — |
| 10 | README et runbook | ⏳ à faire | — |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué.
Une étape n'est ✅ qu'avec une **preuve** : la commande exécutée et son code de sortie, ou le hash du commit.

## Prochaine action

**Étape 2** : le critère de fin est que `make lint` et `make test` passent sur le projet vide.

- Sections de la spec à lire : §9.2 (analyse statique, Deptrac), §9.3 (dépendances dev et versions), §11.1 (Makefile), §12.
- Docker n'arrive qu'à l'étape 3. Vérifier au §11.1 si les cibles `make` passent par `docker compose` ; si c'est le cas, prévoir une exécution sur l'hôte ou le signaler.
- Deptrac : `src/Kernel.php` n'appartient à aucune couche (conforme au §10). Vérifier que le rapport des classes non couvertes ne fait pas échouer `make lint` à cause de ses imports Symfony.
- PHP-CS-Fixer, PHPStan et Deptrac : restreindre l'analyse à `src/`, `tests/` et `config/`. `.claude/skills/architecture-review/evals/files/` contient du PHP de test des skills, à ne pas analyser.
- `lint:container` et `lint:yaml` existent déjà et passent (framework-bundle, yaml) : les intégrer à `make lint`.
- Lire les sorties complètes avec `rtk proxy`, et les codes de sortie avec `$?` sans pipe : le shell est zsh, `PIPESTATUS` n'y existe pas.
- Push en HTTPS uniquement : GitHub refuse la clé SSH locale.
- Pour plus tard :
  - étape 4 : le dépôt est privé, donc la CI GitHub Actions consomme le quota de minutes du compte ;
  - étape 6 : `config/services.yaml` de la recette 8.1 n'a pas de clé `exclude:`, à ajouter pour `Domain/` et `Application/Model/` (§10) ;
  - étape 8 : les routes viennent de `routing.controllers`, c'est-à-dire des services portant `#[Route]`, sans scan de dossier.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` généré par la recette `framework-bundle` 8.1 : conservé, mais adapté pour renvoyer à `CLAUDE.md` et à la spec. Choix du développeur. §10 mis à jour.
- **2026-09-12** — `composer.json` : `"php": "^8.5"` (D1, choix du développeur). `name` et `description` ajoutés pour `composer validate --strict`.
- **2026-09-12** — `src/Controller/`, créé par la recette, supprimé car absent du §10. Commentaire de `routes.yaml` au §10 corrigé (`routing.controllers`).
- **2026-09-13** — Aucune variable d'environnement locale versionnée (demande du développeur) :
  - le `.env.dev` de la recette, qui contient un `APP_SECRET` généré, est renommé `.env.dev.local` et ignoré ;
  - `.env` ne garde que des valeurs par défaut, sans secret ;
  - un clone neuf fonctionne avec `APP_SECRET` vide (`bin/console about` : code 0).
- **2026-09-13** — Dépôt GitHub privé, conforme à D4 (CI GitHub Actions). Fichiers Claude du projet versionnés, sauf `CLAUDE.local.md` et `.claude/settings.local.json`. La règle `deny` sur `git push` est retirée de `.claude/settings.json`.

## Journal des sessions

- **2026-09-13** — Dépôt GitHub privé créé, secret de dev sorti du dépôt, premier commit poussé sur `main`. Prochaine étape : 2.
- **2026-09-12 (soir)** — Étape 1 faite : skeleton Symfony 8.1.6 installé via un dossier temporaire, sans rien écraser ; `git init -b main`. Revue du plan et revue d'architecture passées, sans point bloquant. `AGENTS.md` adapté ; `CLAUDE.md` mis à jour (commandes, `AGENTS.md`). Prochaine étape : 2.
- **2026-09-12** — Review R01 à R12 intégrée (spec, OpenAPI, benchmarks reproductibles). `CLAUDE.md`, skills, agents, workflow et rules en place. Système de suivi créé. Réglages Claude du projet : permissions, hook de lint OpenAPI, plugins caveman et ponytail désactivés localement. Prochaine étape : 1.
- **2026-09-11** — Conception : arbitrages de stack, spec technique et contrat OpenAPI rédigés.

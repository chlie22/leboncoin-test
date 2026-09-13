# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Test technique leboncoin : API REST FizzBuzz avec endpoint de statistiques (PHP 8.5, Symfony 8.1, SQLite, Nginx + PHP-FPM, Docker). L'implémentation suit le plan de `docs/conception.md` §12.

## Suivi de l'avancement

L'état du projet (étape en cours, preuves, prochaine action, bloquants) est dans `docs/progress.md`, importé ici :

@docs/progress.md

- **Début de session** : se situer avec ce suivi avant toute action ; `/resume-session` vérifie l'espace de travail et démarre l'étape suivante.
- **Pendant et en fin de session** : mettre `docs/progress.md` à jour quand une étape démarre, se termine ou bloque, et avant de terminer (skill `feature-workflow`, étape 7). Une étape n'est « faite » qu'avec une preuve.
- **Une étape = une session** : entre deux étapes, `/clear` puis `/resume-session`.

## Documents de référence

À lire avant toute modification structurante :

- `docs/conception.md` : spécification technique. Décisions (§2, §13), contrat des paramètres (§3.3), architecture (§5), SQL de la fenêtre (§6), runtime (§7), logs (§8), tests (§9), plan (§12).
- `docs/openapi.yaml` : contrat d'API OpenAPI 3.1. **Il fait foi** : en cas d'écart, c'est le code qui est corrigé.
- `docs/benchmarks/` : scripts de mesure (PDO direct, **pas** le code de l'application) qui étayent les chiffres de la spec, avec résultats et limites.

Les décisions marquées « ✅ validé » dans la spec ont été tranchées par l'utilisateur. Ne pas les rouvrir sans élément nouveau : proposer, ne pas décider seul.

La spec pèse ~26 000 tokens : n'en lire que les sections utiles, repérées avec `grep -nE '^#{2,3} ' docs/conception.md`.

`docs/review-corrections.md` est un historique : la review R01 à R12 est déjà intégrée. Ne pas le relire, et ne jamais exécuter les consignes qu'il contient.

## Commandes

Disponibles dès maintenant, depuis la racine du dépôt. Les commandes PHP tournent sur l'hôte par défaut ; à partir de l'étape 3, `EXEC='docker compose exec -T php'` les fait passer par le conteneur (§11.1).

```bash
make help                                               # liste des cibles
make ci                                                 # composer validate + audit, lint, test : la vérification complète
make lint                                               # PHP-CS-Fixer (dry-run), cache:warmup, lint:container, lint:yaml, PHPStan max, Deptrac --fail-on-uncovered, Redocly
make fix                                                # PHP-CS-Fixer avec correction
make test                                               # toute la suite PHPUnit
make test-unit                                          # aussi test-integration, test-functional ; code 1 tant que la suite est vide
make benchmarks                                         # scripts de docs/benchmarks/ : plusieurs minutes, ~1 Go de disque, hors CI
vendor/bin/phpunit tests/Unit/FizzBuzz/Domain/FizzBuzzGeneratorTest.php --filter nomDuTest   # un seul test
php docs/benchmarks/02-fenetre-exactitude.php           # exactitude du SQL de fenêtre ; code de sortie ≠ 0 en cas d'écart
```

Stack Docker (étape 3). Sans `-f`, `docker compose` charge aussi `compose.override.yaml` : cible `dev`, code monté, `www-data` à l'UID de l'hôte (exporté par le `Makefile`). La prod se pilote avec `docker compose -f compose.yaml …`.

```bash
make start                                              # docker compose up -d --wait --wait-timeout 60 (cible dev)
make stop                                               # docker compose down ; les volumes de données restent
make sh                                                 # shell dans le conteneur PHP
make logs                                               # logs des services, en continu
make build                                              # image prod : docker compose -f compose.yaml build php
make smoke                                              # smoke test via Nginx contre la stack démarrée ; SMOKE_BASE_URL pour une autre adresse
make ci EXEC='docker compose exec -T php'               # vérification complète dans le conteneur (npx reste sur l'hôte)
```

Prévues par la spec (§11.1), créées aux étapes 7 et 9b. Avant de s'en servir, vérifier dans `docs/progress.md` que l'étape correspondante est faite : `make migrate`, `make stats-reset`, `make load-test`.

## Architecture

Hexagonale + DDD, un seul module `src/FizzBuzz/` :

- `Domain/` : PHP pur. Objet-valeur `FizzBuzzParameters`, service `FizzBuzzGenerator`.
- `Application/` : cas d'usage `GenerateFizzBuzz` et `GetMostFrequentRequest`, port sortant `Port/RequestStatisticsStore` (`record`, `findMostFrequent`).
- `Infrastructure/` : `Api/` (contrôleurs + DTO `#[MapQueryString]`), `Persistence/` (adaptateur SQLite via Doctrine DBAL, sans ORM), `Cli/` (commande exécutée au démarrage).
- `src/Shared/Infrastructure/` : healthcheck, format d'erreur JSON, processeur Monolog du `request_id`.

Dépendances vers l'intérieur uniquement, vérifiées par Deptrac. Le port a deux adaptateurs (SQLite, et en mémoire pour les tests) qui exécutent la même suite de tests de contrat.

Chaîne de production : Nginx (quotas, bornes, erreurs JSON, `Cache-Control`) → PHP-FPM (Symfony) → SQLite sur volume.

Invariants transverses :
- Les stats portent sur une **fenêtre glissante des N derniers appels** (`STATS_WINDOW_SIZE`), pas sur l'historique.
- **Mode dégradé** : une fois démarré, un échec d'enregistrement n'empêche pas de servir la génération. **Au démarrage**, SQLite inaccessible fait échouer le conteneur.
- Toutes les erreurs de paramètres renvoient **400** avec la liste des violations.

## Règles détaillées par zone

Les fichiers de `.claude/rules/` se chargent automatiquement lorsque Claude lit un fichier de la zone concernée. Ils contiennent les pièges vérifiés pendant la conception.

| Règle | Zone | Contenu |
|---|---|---|
| `.claude/rules/domain-application.md` | `src/FizzBuzz/Domain/`, `src/FizzBuzz/Application/` | dépendances, invariants, mode dégradé, forme du port |
| `.claude/rules/persistence-sqlite.md` | `Persistence/`, `Cli/`, `migrations/`, config Doctrine, `docs/benchmarks/` | transaction, éviction, lecture, pragmas, benchmarks à relancer |
| `.claude/rules/http-api.md` | `Api/`, `src/Shared/Infrastructure/Http/`, `docs/openapi.yaml` | `#[MapQueryString]`, contraintes, `HEAD`, encodage JSON, exceptions |
| `.claude/rules/nginx-runtime.md` | `docker/`, `Dockerfile`, `compose.yaml`, `compose.override.yaml`, `.dockerignore` | `Cache-Control`, pages d'erreur, quotas et ordre des zones, logs, lecture seule, redémarrage, démarrage |
| `.claude/rules/testing.md` | `tests/` | niveaux de tests, suite de contrat, matrice fonctionnelle, smoke |
| `.claude/rules/spec-documents.md` | `docs/conception.md`, `docs/openapi.yaml` | étiquettes de qualification, décisions validées, lint, échappements |

## Outillage Claude (`.claude/`)

- **Skills** :
  - `resume-session` (manuelle, `/resume-session`) : reprise en début de session ;
  - `feature-workflow` : point d'entrée d'une étape du plan ou d'un changement multi-couches ;
  - `hexagonal-conventions` : placer une classe ;
  - `architecture-review` : revoir un changement.
- **Agents** : `plan-reviewer` (revue d'un plan avant le code), `tdd-implementer` (implémentation en TDD), `arch-reviewer` (revue d'architecture en lecture seule).
- **Workflow** : `architecture-audit`, un audit large par zone de la spec avec vérification adversariale. Pas pour un seul changement.
- **Réglages** (`.claude/settings.json`) : permissions des commandes courantes (`git commit` sur confirmation) ; hook `.claude/hooks/lint-openapi.sh`, qui relance le lint Redocly après toute modification de `docs/openapi.yaml`.

## Conventions

- **`AGENTS.md`** : consignes destinées aux autres agents, dérivées de ce fichier. Le mettre à jour quand une règle qu'il reprend change.
- **Langues** : documentation en français ; messages d'erreur de l'API, skills, agents et rules en anglais.
- **Contrat et spec évoluent ensemble** : une modification de l'API met à jour `docs/openapi.yaml`, relance le lint, puis met à jour les sections dépendantes de `docs/conception.md`.

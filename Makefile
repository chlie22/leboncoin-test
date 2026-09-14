# Cibles du projet (docs/conception.md §11.1).
#
# Les commandes PHP et Composer tournent sur l'hôte par défaut, comme les jobs de la CI.
# Les cibles ci, lint et test sont reprises par .github/workflows/ci.yaml : modifier les deux ensemble.
# Pour les exécuter dans le conteneur PHP (étape 3 et suivantes) :
#   make lint EXEC='docker compose exec -T php'
# Le lint OpenAPI (npx) tourne toujours sur l'hôte.
# Exception : migrate et stats-reset visent la base de la stack, donc le conteneur PHP par défaut ;
# EXEC les redirige (prod : EXEC='docker compose -f compose.yaml exec -T php').

EXEC ?=
PHP = $(EXEC) php
COMPOSER = $(EXEC) composer
REDOCLY = npx --yes @redocly/cli@2.52.1
COMPOSE = docker compose
DB_EXEC = $(or $(EXEC),$(COMPOSE) exec -T php)
SMOKE_BASE_URL ?= http://127.0.0.1:8080
LOAD_BASE_URL ?= http://nginx:8080
SCENARIO ?= nominal
K6_IMAGE ?= grafana/k6:1.3.0

# UID et GID de l'hôte, repris par www-data dans l'image dev (compose.override.yaml).
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help start stop sh logs build install migrate stats-reset test-db test test-unit test-integration test-functional smoke load-test lint fix ci benchmarks

help: ## Liste des cibles
	@grep -hE '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-18s %s\n", $$1, $$2}'

start: ## Démarre la stack Docker (cible dev en local) et attend que les conteneurs tournent
	$(COMPOSE) up -d --wait --wait-timeout 60

stop: ## Arrête la stack (les volumes de données sont conservés)
	$(COMPOSE) down

sh: ## Shell dans le conteneur PHP
	$(COMPOSE) exec php bash

logs: ## Logs des services, en continu
	$(COMPOSE) logs -f

build: ## Construit l'image prod (compose.yaml seul, sans la surcharge dev)
	$(COMPOSE) -f compose.yaml build php

install: ## Installe les dépendances Composer
	$(COMPOSER) install

migrate: ## Migrations de la base de la stack (conteneur PHP par défaut)
	$(DB_EXEC) php bin/console doctrine:migrations:migrate --no-interaction

# Une seule chaîne SQL, journal d'abord (clés étrangères) : un échec n'efface rien.
stats-reset: ## Vide les deux tables de statistiques de la stack, après confirmation (conteneur PHP par défaut)
	@printf 'Vider fizzbuzz_request_log et fizzbuzz_request_stat ? [y/N] ' && read answer && [ "$$answer" = y ] || { echo 'Annulé.'; exit 1; }
	$(DB_EXEC) php bin/console dbal:run-sql 'BEGIN; DELETE FROM fizzbuzz_request_log; DELETE FROM fizzbuzz_request_stat; COMMIT'

test-db: ## Migrations de la base de test (DATABASE_URL de .env.test)
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction --env=test

test: test-db ## Toute la suite PHPUnit
	$(PHP) vendor/bin/phpunit

test-unit: ## Tests unitaires (Domain, Application, adaptateur en mémoire)
	$(PHP) vendor/bin/phpunit --testsuite unit

test-integration: test-db ## Tests d'intégration (SQLite, commande de démarrage, concurrence)
	$(PHP) vendor/bin/phpunit --testsuite integration

test-functional: test-db ## Tests fonctionnels (WebTestCase)
	$(PHP) vendor/bin/phpunit --testsuite functional

smoke: ## Smoke test via Nginx, contre la stack PROD démarrée ; arrête et relance les conteneurs, laisse la prod démarrée
	BASE_URL=$(SMOKE_BASE_URL) tests/Smoke/smoke.sh

# Image prod + stack compose.yaml avec quotas relevés ; k6 via grafana/k6 ; seuils bloquants sur SCENARIO=nominal.
# Scénarios complémentaires : SCENARIO=worst|ramp|contention|quotas make load-test
load-test: build ## Test de charge k6 contre la stack prod (§9.4) ; SCENARIO=nominal par défaut
	LOAD_BASE_URL=$(LOAD_BASE_URL) SCENARIO=$(SCENARIO) K6_IMAGE=$(K6_IMAGE) tests/Load/run-load-test.sh

lint: ## PHP-CS-Fixer (dry-run), container, YAML, PHPStan, Deptrac, OpenAPI
	$(PHP) vendor/bin/php-cs-fixer check --diff
	$(PHP) bin/console cache:warmup --env=dev
	$(PHP) bin/console lint:container
	$(PHP) bin/console lint:yaml config --parse-tags
	$(PHP) vendor/bin/phpstan analyse --no-progress
	$(PHP) vendor/bin/deptrac analyse --fail-on-uncovered --no-progress
	$(REDOCLY) lint

fix: ## PHP-CS-Fixer avec correction
	$(PHP) vendor/bin/php-cs-fixer fix

ci: ## composer validate et audit, puis lint et test (identique à la CI)
	$(COMPOSER) validate --strict
	$(COMPOSER) audit
	$(MAKE) lint
	$(MAKE) test

benchmarks: ## Scripts de docs/benchmarks/ (plusieurs minutes, ~1 Go de disque, hors CI)
	$(PHP) docs/benchmarks/02-fenetre-exactitude.php
	$(PHP) docs/benchmarks/03-json-reponse.php
	$(PHP) -d memory_limit=1G docs/benchmarks/01-stockage.php

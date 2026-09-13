# Cibles du projet (docs/conception.md §11.1).
#
# Les commandes PHP et Composer tournent sur l'hôte par défaut, comme les jobs de la CI.
# Pour les exécuter dans le conteneur PHP (étape 3 et suivantes) :
#   make lint EXEC='docker compose exec -T php'
# Le lint OpenAPI (npx) tourne toujours sur l'hôte.

EXEC ?=
PHP = $(EXEC) php
COMPOSER = $(EXEC) composer
REDOCLY = npx --yes @redocly/cli@2.52.1
COMPOSE = docker compose
SMOKE_BASE_URL ?= http://127.0.0.1:8080

# UID et GID de l'hôte, repris par www-data dans l'image dev (compose.override.yaml).
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help start stop sh logs build install test test-unit test-integration test-functional smoke lint fix ci benchmarks

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

test: ## Toute la suite PHPUnit
	$(PHP) vendor/bin/phpunit

test-unit: ## Tests unitaires (Domain, Application, adaptateur en mémoire)
	$(PHP) vendor/bin/phpunit --testsuite unit

test-integration: ## Tests d'intégration (SQLite, commande de démarrage, concurrence)
	$(PHP) vendor/bin/phpunit --testsuite integration

test-functional: ## Tests fonctionnels (WebTestCase)
	$(PHP) vendor/bin/phpunit --testsuite functional

smoke: ## Smoke test via Nginx, contre la stack démarrée (make start)
	BASE_URL=$(SMOKE_BASE_URL) tests/Smoke/smoke.sh

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

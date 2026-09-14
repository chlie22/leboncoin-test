# AGENTS.md

Guidance for AI coding agents other than Claude Code. Claude Code reads `CLAUDE.md` and `.claude/rules/`, which are the source of truth: when this file and them disagree, they win and this file is fixed.

## Project

FizzBuzz REST API with a statistics endpoint (leboncoin technical test): PHP 8.5, Symfony 8.1 skeleton, SQLite, Nginx + PHP-FPM, Docker.

Read before any structural change:

- `docs/conception.md`: technical specification. Decisions marked validated (§2, §13) are not reopened without the developer's agreement. List its headings with `grep -nE '^#{2,3} ' docs/conception.md` and read only the sections you need.
- `docs/openapi.yaml`: the API contract. It wins: when code and contract differ, the code is fixed.
- `docs/progress.md`: current step of the implementation plan (§12), with proofs. Update it when a step starts, ends or is blocked.

## Where this project departs from default Symfony guidance

- **Architecture**: hexagonal + DDD in `src/FizzBuzz/{Domain,Application,Infrastructure}` and `src/Shared/Infrastructure/`. `Domain/` is plain PHP with no framework dependency; dependencies point inwards and Deptrac enforces it. Controllers live in `src/FizzBuzz/Infrastructure/Api/` and `src/Shared/Infrastructure/Http/` (healthcheck), not `src/Controller/`.
- **Persistence**: Doctrine DBAL and Doctrine Migrations, **no ORM** (D11). Do not install `orm-pack` and do not use `make:entity`, `make:migration` or `doctrine:schema:update`.
- **HTTP input**: `#[MapQueryString]` with a DTO and Validator constraints (D12). Every parameter error returns 400 with the list of violations, as `application/problem+json` (D13).
- **Scope**: no use-case interface, command bus, domain event, aggregate, generic repository or circuit breaker (`docs/conception.md` §5.9). Install only the packages listed in §9.3, at the plan step that needs them.
- **Statistics**: computed over a sliding window of the last N calls, not the whole history. Once started, a failure to record a call never prevents serving the FizzBuzz response (degraded mode).

## Workflow

- One plan step per session; tests are written before business code, from Domain outwards (`docs/conception.md` §9.1).
- Commands: use the Makefile targets (`make help`).
  - They run on the host by default, like CI; optionally, they run inside the PHP container through `EXEC`, for example `make lint EXEC='docker compose exec -T php'`.
  - Exception: `make migrate` and `make stats-reset` target the stack's database, so they run in the PHP container by default.
  - `make test` first migrates the SQLite test database `var/test.db` (`make test-db`). Run `make test-db` before calling `vendor/bin/phpunit` directly on a fresh clone.
- CI: `.github/workflows/ci.yaml` (jobs `quality`, `tests`, `openapi`, `docker`) repeats the commands of the `ci`, `lint` and `test` Makefile targets: change both together. Actions are pinned by commit SHA; lint the workflow with `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:1.7.12 -color`.
- Docker: `make start` / `make stop` / `make smoke` / `make load-test`. `make smoke` and `make load-test` need the production stack (`make build`, then `docker compose -f compose.yaml …`) and refuse the dev stack. `make load-test` raises `RATE_LIMIT_*`, runs `grafana/k6` against Nginx, applies blocking thresholds on `SCENARIO=nominal`, then restores production quotas. Plain `docker compose` also loads `compose.override.yaml` (dev target, mounted code); drive the production stack with `docker compose -f compose.yaml …` (`make build` builds its image).
- Discover instead of guessing: `bin/console about`, `debug:container`, `debug:router`, `lint:container`, `lint:yaml config --parse-tags`, and the installed sources under `vendor/`.
- `.env` is committed and holds defaults only; local overrides go in `.env.local` (git-ignored).
- Never commit or push unless the developer asks. Push over HTTPS (`origin`).

## Languages

Documentation in French; API error messages, code, agent and tooling instructions in English.
